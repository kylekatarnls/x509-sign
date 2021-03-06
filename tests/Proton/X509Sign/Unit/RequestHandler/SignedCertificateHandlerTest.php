<?php

declare(strict_types=1);

namespace Tests\Proton\X509Sign\Unit\RequestHandler;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;
use phpseclib3\File\ASN1;
use phpseclib3\File\X509;
use Proton\X509Sign\Key;
use Proton\X509Sign\Issuer;
use Proton\X509Sign\RequestHandler\SignedCertificateHandler;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Proton\X509Sign\Fixture\Application;
use Tests\Proton\X509Sign\Fixture\User;
use Tests\Proton\X509Sign\TestCase;

/**
 * @coversDefaultClass \Proton\X509Sign\RequestHandler\SignedCertificateHandler
 */
class SignedCertificateHandlerTest extends TestCase
{
    /**
     * @covers ::__construct
     */
    public function testConstructor(): void
    {
        $property = new ReflectionProperty(SignedCertificateHandler::class, 'issuer');
        $property->setAccessible(true);

        self::assertInstanceOf(Issuer::class, $property->getValue(new SignedCertificateHandler()));

        $issuer = new Issuer();

        self::assertSame($issuer, $property->getValue(new SignedCertificateHandler($issuer)));
    }

    /**
     * @covers ::handle
     */
    public function testHandle(): void
    {
        /** @var PrivateKey $signServerPrivateKey */
        $signServerPrivateKey = PrivateKey::createKey();

        /** @var PublicKey $signServerPublicKey */
        $signServerPublicKey = $signServerPrivateKey->getPublicKey();

        $application = new Application();
        $user = new User(RSA::createKey());
        $application->receiveRequestFromUser($user);
        $certificate = $application->generateCertificate($signServerPublicKey);

        $result = (new SignedCertificateHandler())->handle(
            $signServerPrivateKey,
            [
                'EXTENSIONS' => json_encode([$application->getExtension()]),
            ],
            [
                'certificate' => $certificate,
                'clientPublicKey' => $user->getPublicKey(),
                'mode' => Key::RSA,
            ],
        );

        self::assertNotSame($certificate, $result);

        [
            'hours' => $hours,
            'issuer' => $issuer,
            'subject' => $subject,
            'serialNumber' => $serialNumber,
        ] = $this->getCertificateData($result);

        self::assertSame(24, $hours);
        self::assertSame($application->getIssuerDn(), $issuer);
        self::assertSame($user->getSubjectDn(), $subject);
        self::assertSame('42', $serialNumber);
    }

    /**
     * @covers ::handle
     */
    public function testHandleWithEd25519Key(): void
    {
        $signServerPrivateKey = EC::createKey('Ed25519');

        /** @var EC\PublicKey $signServerPublicKey */
        $signServerPublicKey = $signServerPrivateKey->getPublicKey();

        $application = new Application();
        $user = new User();
        $application->receiveRequestFromUser($user);
        $certificate = $application->generateCertificate($signServerPublicKey);

        $result = (new SignedCertificateHandler())->handle(
            $signServerPrivateKey,
            [
                'EXTENSIONS' => json_encode([$application->getExtension()]),
            ],
            [
                'certificate' => $certificate,
                'clientPublicKey' => $user->getPublicKey(),
            ],
        );

        self::assertNotSame($certificate, $result);

        [
            'hours' => $hours,
            'issuer' => $issuer,
            'subject' => $subject,
            'serialNumber' => $serialNumber,
        ] = $this->getCertificateData($result);

        self::assertSame(24, $hours);
        self::assertSame($application->getIssuerDn(), $issuer);
        self::assertSame($user->getSubjectDn(), $subject);
        self::assertSame('42', $serialNumber);
    }

    /**
     * @covers ::handle
     * @covers ::issueCertificateData
     */
    public function testHandleWithCertificateData(): void
    {
        $signServerPrivateKey = EC::createKey('Ed25519');

        $application = new Application();
        $user = new User();
        $clientPublicKey = $user->getPublicKey();

        $result = (new SignedCertificateHandler())->handle(
            $signServerPrivateKey,
            [],
            [
                'extensions' => [$application->getExtension()],
                'certificateData' => [
                    'issuerDN' => ['commonName' => 'Foo'],
                    'subjectDN' => ['commonName' => 'Bar'],
                    'serialNumber' => '123',
                    'notBefore' => '2021-06-24T12:00:00.000000Z',
                    'notAfter' => '2021-07-24T12:00:00.000000Z',
                    'extensions' => [
                        Application::NAME => [
                            'cool' => true,
                            'level' => 22,
                            'name' => 'Audrey',
                        ],
                    ],
                ],
                'clientPublicKey' => $user->getPublicKey(),
            ],
        );

        $data = $this->getCertificateData($result);
        $tbs = $data['ca']['tbsCertificate'];

        self::assertSame('123', (string) $tbs['serialNumber']);
        $issuer = $tbs['issuer']['rdnSequence'][0][0];
        self::assertSame('id-at-commonName', $issuer['type']);
        self::assertSame('Foo', $issuer['value']['utf8String']);
        $subject = $tbs['subject']['rdnSequence'][0][0];
        self::assertSame('id-at-commonName', $subject['type']);
        self::assertSame('Bar', $subject['value']['utf8String']);
        self::assertSame('Thu, 24 Jun 2021 12:00:00 +0000', $tbs['validity']['notBefore']['utcTime']);
        self::assertSame('Sat, 24 Jul 2021 12:00:00 +0000', $tbs['validity']['notAfter']['utcTime']);
        self::assertSame($clientPublicKey, $tbs['subjectPublicKeyInfo']['subjectPublicKey']);
        $tbs['extensions'][1]['extnValue']['level'] = (string) $tbs['extensions'][1]['extnValue']['level'];
        self::assertSame([
            [
                'extnId' => 'id-ce-subjectKeyIdentifier',
                'critical' => false,
                'extnValue' => (new X509())->computeKeyIdentifier($clientPublicKey),
            ],
            [
                'extnId' => Application::NAME,
                'critical' => false,
                'extnValue' => [
                    'cool' => true,
                    'level' => '22',
                    'name' => 'Audrey',
                ],
            ],
        ], $tbs['extensions']);
    }

    /**
     * @covers ::handle
     */
    public function testHandleIncorrectCertificate(): void
    {
        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Unable to sign the CSR.');

        $handler = new SignedCertificateHandler();
        /** @var PrivateKey $privateKey */
        $privateKey = PrivateKey::createKey()->withPassword('Le petit chien est sur la pente fatale.');

        $handler->handle(
            $privateKey,
            [],
            [
                'certificate' => 'foobar',
                'clientPublicKey' => (new User(RSA::createKey()))->getPublicKey(),
                'mode' => Key::RSA,
            ],
        );
    }

    /**
     * @covers ::reIssueCertificate
     */
    public function testReIssueCertificate()
    {
        $handler = new SignedCertificateHandler();

        $callReIssueCertificate = function (
            Application $application,
            string $certificate,
            PrivateKey $issuerKey,
            PublicKey $subjectKey
        ) use ($handler) {
            $loadExtensions = new ReflectionMethod(SignedCertificateHandler::class, 'loadExtensions');
            $loadExtensions->setAccessible(true);
            $loadExtensions->invoke($handler, [$application->getExtension()]);

            $reIssueCertificate = new ReflectionMethod(SignedCertificateHandler::class, 'reIssueCertificate');
            $reIssueCertificate->setAccessible(true);

            return $reIssueCertificate->invoke($handler, $certificate, $issuerKey, $subjectKey);
        };

        $application = new Application();

        self::assertNull($callReIssueCertificate(
            $application,
            'foobar',
            PrivateKey::createKey(),
            PrivateKey::createKey()->getPublicKey(),
        ));

        /** @var PrivateKey $signServerPrivateKey */
        $signServerPrivateKey = PrivateKey::createKey();

        /** @var PublicKey $signServerPublicKey */
        $signServerPublicKey = $signServerPrivateKey->getPublicKey();

        $user = new User(RSA::createKey());
        $application->setUserData($user->getSubjectDn()['commonName'], ['level' => 12]);
        $application->receiveRequestFromUser($user);
        $certificate = $application->generateCertificate($signServerPublicKey);

        /** @var PublicKey $userPublicKey */
        $userPublicKey = PublicKey::load($user->getPublicKey());

        $reIssuedCertificate = $callReIssueCertificate(
            $application,
            $certificate,
            $signServerPrivateKey,
            $userPublicKey,
        );

        self::assertIsString($reIssuedCertificate);

        $extension = $this->getCertificateData($reIssuedCertificate)['extensions']['super'];

        self::assertSame('12', (string) $extension['level']);
    }

    /**
     * @covers ::loadExtensions
     */
    public function testLoadExtensions()
    {
        $handler = new SignedCertificateHandler();
        $loadExtensions = new ReflectionMethod(SignedCertificateHandler::class, 'loadExtensions');
        $loadExtensions->setAccessible(true);
        $loadExtensions->invoke($handler, [
            [
                'my-id',
                'my-code',
                ['type' => ASN1::TYPE_INTEGER],
            ],
            [
                'foo',
                'bar',
                ['type' => ASN1::TYPE_ANY],
            ],
        ]);

        $oidsReflector = new ReflectionProperty(ASN1::class, 'oids');
        $oidsReflector->setAccessible(true);

        self::assertSame('my-id', $oidsReflector->getValue()['my-code']);
        self::assertSame('foo', $oidsReflector->getValue()['bar']);

        $extensionsReflector = new ReflectionProperty(X509::class, 'extensions');
        $extensionsReflector->setAccessible(true);

        self::assertSame(['type' => ASN1::TYPE_INTEGER], $extensionsReflector->getValue()['my-id']);
        self::assertSame(['type' => ASN1::TYPE_ANY], $extensionsReflector->getValue()['foo']);
    }

    /**
     * @covers ::getExtensionsValues
     */
    public function testGetExtensionsValues(): void
    {
        $handler = new SignedCertificateHandler();
        $getExtensionsValues = new ReflectionMethod(SignedCertificateHandler::class, 'getExtensionsValues');
        $getExtensionsValues->setAccessible(true);
        $callGetExtensionsValues = function (array $certificateData) use ($handler, $getExtensionsValues): array {
            return iterator_to_array($getExtensionsValues->invoke($handler, $certificateData));
        };

        self::assertSame([], $callGetExtensionsValues([]));
        self::assertSame([
            'first' => 1,
            'second' => [2 => 2],
        ], $callGetExtensionsValues([
            'extensions' => [
                [
                    'extnId' => 'first',
                    'extnValue' => 1,
                    'critical' => false,
                ],
                [
                    'extnId' => 'second',
                    'extnValue' => [2 => 2],
                    'critical' => true,
                ],
            ],
        ]));
    }
}
