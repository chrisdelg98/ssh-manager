<?php
declare(strict_types=1);

namespace App;

use PDO;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\Common\PublicKey;

/**
 * Manages user SSH private keys.
 * All key material is encrypted at rest with the user's enc_key (AES-256-GCM).
 * Fingerprints are stored in cleartext as identifiers — they reveal nothing about the secret.
 */
class SshKeyManager
{
    private PDO    $db;
    private string $encKey;

    public function __construct(PDO $db, string $encKey)
    {
        $this->db     = $db;
        $this->encKey = $encKey;
    }

    /**
     * List keys without secret material.
     */
    public function listAll(): array
    {
        return $this->db->query(
            'SELECT id, name, key_type, bits, fingerprint, created_at
             FROM ssh_keys ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single key (with decrypted private key, public key, passphrase).
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ssh_keys WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['private_key'] = Encryption::decrypt($row['private_key_enc'], $this->encKey);
        $row['public_key']  = $row['public_key_enc']  ? Encryption::decrypt($row['public_key_enc'], $this->encKey)  : null;
        $row['passphrase']  = $row['passphrase_enc']  ? Encryption::decrypt($row['passphrase_enc'], $this->encKey)  : null;
        $row['notes']       = $row['notes_enc']       ? Encryption::decrypt($row['notes_enc'], $this->encKey)       : null;

        unset($row['private_key_enc'], $row['public_key_enc'], $row['passphrase_enc'], $row['notes_enc']);
        return $row;
    }

    /**
     * Add a new key. The private key is parsed to extract metadata.
     *
     * @return array{id:int, name:string, key_type:string, bits:int|null, fingerprint:string}
     * @throws \RuntimeException if parsing fails (wrong passphrase, malformed key, etc.)
     */
    public function create(string $name, string $privateKey, ?string $passphrase = null, ?string $notes = null): array
    {
        $meta = self::parsePrivate($privateKey, $passphrase);

        $stmt = $this->db->prepare(
            'INSERT INTO ssh_keys
             (name, key_type, bits, fingerprint, public_key_enc, private_key_enc, passphrase_enc, notes_enc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $meta['key_type'],
            $meta['bits'],
            $meta['fingerprint'],
            $meta['public_key'] ? Encryption::encrypt($meta['public_key'], $this->encKey) : null,
            Encryption::encrypt($privateKey, $this->encKey),
            $passphrase ? Encryption::encrypt($passphrase, $this->encKey) : null,
            $notes      ? Encryption::encrypt($notes, $this->encKey)      : null,
        ]);

        $id = (int)$this->db->lastInsertId();
        return [
            'id'          => $id,
            'name'        => $name,
            'key_type'    => $meta['key_type'],
            'bits'        => $meta['bits'],
            'fingerprint' => $meta['fingerprint'],
        ];
    }

    /**
     * Update name and notes only (the secret material is immutable here — to swap key,
     * delete and recreate; that keeps the audit trail honest).
     */
    public function updateMeta(int $id, string $name, ?string $notes): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ssh_keys SET name = ?, notes_enc = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $notes ? Encryption::encrypt($notes, $this->encKey) : null,
            $id,
        ]);
    }

    /**
     * Hard delete. Servers referencing this key keep their ssh_key_id but it becomes a dangling
     * pointer — UI should warn before delete. Enforce by checking referenced first.
     */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM ssh_keys WHERE id = ?')->execute([$id]);
    }

    /**
     * Count servers currently associated with this key.
     */
    public function countLinkedServers(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM servers WHERE ssh_key_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Parse a private-key blob and extract type/bits/fingerprint/public-key (OpenSSH format).
     */
    public static function parsePrivate(string $privateKey, ?string $passphrase = null): array
    {
        try {
            /** @var PrivateKey|AsymmetricKey $key */
            $key = PublicKeyLoader::loadPrivateKey($privateKey, $passphrase ?? '');
        } catch (\Throwable $e) {
            $msg = $passphrase
                ? 'No se pudo cargar la clave privada. ¿Passphrase correcta?'
                : 'No se pudo cargar la clave privada. Si tiene passphrase, debes proporcionarla.';
            throw new \RuntimeException($msg);
        }

        /** @var PublicKey $pub */
        $pub = $key->getPublicKey();

        // OpenSSH public key string (no comment)
        $opensshPub = self::tryFormat($pub, 'OpenSSH', '');

        // SHA256 fingerprint of the public key blob (OpenSSH convention: base64 of raw sha256)
        $fingerprint = 'SHA256:' . self::opensshFingerprint($opensshPub);

        // Key type/bits — pull from class name & loaded params
        $cls = get_class($key);
        $type = match (true) {
            str_contains($cls, 'RSA')     => 'rsa',
            str_contains($cls, 'EC')      => self::isEd25519($key) ? 'ed25519' : 'ecdsa',
            str_contains($cls, 'DSA')     => 'dsa',
            default                       => 'other',
        };

        $bits = null;
        if (method_exists($key, 'getLength')) {
            $bits = (int)$key->getLength();
        }

        return [
            'key_type'    => $type,
            'bits'        => $bits,
            'fingerprint' => $fingerprint,
            'public_key'  => $opensshPub,
        ];
    }

    private static function isEd25519(object $key): bool
    {
        try {
            if (method_exists($key, 'getCurve')) {
                return strtolower((string)$key->getCurve()) === 'ed25519';
            }
        } catch (\Throwable) { /* fall through */ }
        return false;
    }

    private static function tryFormat(PublicKey $pub, string $format, string $fallback): string
    {
        try {
            return (string)$pub->toString($format);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Compute the standard OpenSSH SHA256 fingerprint from an "ssh-rsa AAAA... [comment]" line.
     * Returns base64 (rstrip "=") of the raw sha256 of the base64-decoded key data — this matches
     * what `ssh-keygen -lf key.pub` prints after the "SHA256:" prefix.
     */
    private static function opensshFingerprint(string $opensshLine): string
    {
        $parts = preg_split('/\s+/', trim($opensshLine), 3);
        if (count($parts) < 2) {
            return 'unknown';
        }
        $blob = base64_decode($parts[1], true);
        if ($blob === false) {
            return 'unknown';
        }
        return rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
