<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Generates, formats, normalizes, and hashes account-recovery codes.
 *
 * A code is 12 characters drawn from an unambiguous uppercase alphabet (no
 * O/0/I/1/L), displayed as three hyphen-separated groups, e.g. 7KMP-9XRT-4WQN.
 * Accounts store an encrypted copy for authorized retrieval and a hash for
 * verification. Reset requests bind the hash to a one-hour approval window.
 */
class ResetCodeService
{
    /** Ensure existing accounts have a code; serialize concurrent first retrievals. */
    public function forAccount(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($account->recovery_code === null) {
                $this->rotate($account);
            }

            return $account->recovery_code;
        });
    }

    public function rotate(User $user): void
    {
        $code = $this->generate();
        $user->recovery_code = $code['plain'];
        $user->recovery_code_hash = $code['hash'];
        $user->save();
    }

    /** Unambiguous uppercase letters + digits (excludes O, 0, I, 1, L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 12;

    private const GROUP = 4;

    /**
     * Create a new code.
     *
     * @return array{plain: string, hash: string}
     */
    public function generate(): array
    {
        $raw = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $raw .= self::ALPHABET[random_int(0, $max)];
        }

        return [
            'plain' => $this->format($raw),
            'hash' => $this->hash($raw),
        ];
    }

    /**
     * Group a bare 12-character code for display (ABCD-EFGH-JKMN).
     */
    public function format(string $normalized): string
    {
        return implode('-', str_split($normalized, self::GROUP));
    }

    /**
     * Normalize user input: uppercase and strip every non-alphabet character
     * (spaces, hyphens, lowercase) so formatting and case never affect matching.
     */
    public function normalize(string $input): string
    {
        $upper = strtoupper($input);

        return preg_replace('/[^'.self::ALPHABET.']/', '', $upper) ?? '';
    }

    /**
     * True when a normalized code is structurally valid (right length + alphabet).
     */
    public function isWellFormed(string $normalized): bool
    {
        return strlen($normalized) === self::LENGTH
            && preg_match('/\A['.self::ALPHABET.']+\z/', $normalized) === 1;
    }

    /**
     * SHA-256 hash of a code. Accepts either a bare or formatted code.
     */
    public function hash(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }
}
