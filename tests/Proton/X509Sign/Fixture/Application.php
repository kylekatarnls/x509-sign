<?php

declare(strict_types=1);

namespace Tests\Proton\X509Sign\Fixture;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\File\X509;
use Proton\X509Sign\Key;
use Proton\X509Sign\Server;

/**
 * Application class represents a separated application using the signature endpoint
 * to get a certificate re-signed.
 */
final class Application
{
    use SharedExtension;

    public const NAME = 'super';

    private array $issuerDn = [
        'countryName' => 'US',
        'stateOrProvinceName' => 'NY',
        'localityName' => 'New York',
        'organizationName' => 'Any Organization',
        'organizationalUnitName' => 'Some Department',
        'commonName' => 'Dream Team',
        'emailAddress' => 'dreamteam@any.org',
    ];

    private array $usersDatabase = [
        'Alan' => [
            'cool' => true,
            'level' => 73,
        ],
    ];

    private PrivateKey $applicationKey;

    private ?User $currentUser = null;

    private ?Server $signatureServer = null;

    private ?string $signatureServerPublicKey = null;

    private ?string $signatureServerPublicKeyMode = null;

    private bool $satisfied = false;

    public function __construct()
    {
        $this->applicationKey = EC::createKey('ed25519');
    }

    public function receiveRequestFromUser(User $user): void
    {
        $this->currentUser = $user;
    }

    public function generateCertificate(PublicKey $signServerPublicKey): string
    {
        $this->loadASN1Extension();

        $userData = $this->currentUser->getSubjectDn();
        ['commonName' => $userName] = $userData;

        $subject = new X509();
        $subject->setPublicKey($signServerPublicKey);
        $subject->setDN($userData);

        $issuer = new X509();
        $issuer->setPrivateKey($this->applicationKey);
        $issuer->setDN($this->issuerDn);

        $x509 = new X509();
        $x509->makeCA();
        $x509->setSerialNumber('42', 10);
        $x509->setStartDate('-1 second');
        $x509->setEndDate('1 day');
        $x509->setExtensionValue(self::NAME, [
            'cool' => $this->usersDatabase[$userName]['cool'],
            'level' => $this->usersDatabase[$userName]['level'],
            'name' => $userName,
        ]);

        $certificate = $x509->saveX509($x509->sign($issuer, $subject));

        $this->unloadASN1Extension();

        return $certificate;
    }

    /**
     * @return array|string[]
     */
    public function getIssuerDn(): array
    {
        return $this->issuerDn;
    }

    public function connectToSignatureServer(Server $signatureServer): void
    {
        $this->signatureServerPublicKey = null;
        $this->signatureServer = $signatureServer;
    }

    public function getSignedCertificate(?string $mode = null): ?string
    {
        $parameters = [
            'certificate' => $this->generateCertificate($this->getSignatureServerPublicKey()),
            'clientPublicKey' => $this->currentUser->getPublicKey(),
        ];

        if ($mode) {
            $parameters['mode'] = $mode;
        }

        $response = $this->postJson([
            'signedCertificate' => $parameters,
        ]);

        if (!($response['signedCertificate']['success'] ?? false)) {
            return null;
        }

        /** @var string $certificate */
        $certificate = $response['signedCertificate']['result'];

        return $certificate;
    }

    public function askForSignature(?string $mode = null): void
    {
        /** @var string|null $certificate */
        $certificate = $this->getSignedCertificate($mode);

        if (!$certificate) {
            return;
        }

        $this->currentUser->receiveCertificate($certificate);

        $x509 = new X509();
        $data = $x509->loadX509($certificate);

        $time = strtotime($data['tbsCertificate']['validity']['notAfter']['utcTime']);
        $hours = (int) round(($time - time()) / 3600);

        $superAppExtension = $this->getFirstExtensionValue($data);

        $this->satisfied = (
            $hours === 24 &&
            (string) $data['tbsCertificate']['serialNumber'] === '42' &&
            $superAppExtension['cool'] &&
            (string) $superAppExtension['level'] === '73' &&
            (string) $superAppExtension['name'] === 'Alan' &&
            $this->currentUser->isSatisfiedWithItsCertificate()
        );
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied;
    }

    public function setUserData(string $name, array $data): void
    {
        $this->usersDatabase[$name] = array_merge($this->usersDatabase[$name], $data);
    }

    private function getFirstExtensionValue(array $data)
    {
        foreach ($data['tbsCertificate']['extensions'] as $extension) {
            if ($extension['extnId'] === self::NAME) {
                return $extension['extnValue'] ?? null;
            }
        }

        return null;
    }

    private function getSignatureServerPublicKey(): PublicKey
    {
        if (!$this->signatureServerPublicKey) {
            $response = $this->postJson([
                'publicKey' => [],
                'publicKeyMode' => [],
            ]);

            $this->signatureServerPublicKey = $response['publicKey']['result'];
            $this->signatureServerPublicKeyMode = $response['publicKeyMode']['result'];
        }

        return Key::loadPublic($this->signatureServerPublicKeyMode, $this->signatureServerPublicKey);
    }

    /**
     * @param array<string, array> $requests
     * @return array<string, array{success: bool, error?: string, result?: mixed}>
     */
    private function postJson(array $requests): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'x509-sign');
        $handler = fopen($tempFile, 'w+');
        $this->signatureServer->handleRequests($requests, $handler);
        fclose($handler);
        $contents = file_get_contents($tempFile);
        unlink($tempFile);

        return json_decode($contents, true);
    }
}
