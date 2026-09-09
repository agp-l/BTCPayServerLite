<?php

declare(strict_types=1);
namespace BtcPayLite;
use PDO;

/** Revocable device credential. Only validator hashes are stored in the database. */
final class RememberedLogin
{
    public const COOKIE = 'BTCPAYLITEREMEMBER';
    public const LIFETIME = 2592000; // Fixed 30 days after password login.
    public function __construct(private PDO $pdo) {}

    public function issue(array $user, ?int $now = null): string
    {
        $now ??= time();
        $selector = bin2hex(random_bytes(16)); $validator = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO remembered_logins (selector,validator_hash,user_id,session_version,expires_at,rotated_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$selector,hash('sha256',$validator),$user['id'],$user['session_version'],$now+self::LIFETIME,$now]);
        $stmt = $this->pdo->prepare('DELETE FROM remembered_logins WHERE expires_at < ? LIMIT 100'); $stmt->execute([$now]);
        return $selector . '.' . $validator;
    }

    /** @return array{user:array,cookie:?string,expires_at:int}|null */
    public function resume(string $cookie, ?int $now = null): ?array
    {
        $parts = self::parts($cookie); if ($parts === null) { return null; }
        $now ??= time(); [$selector,$validator] = $parts;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT r.*,u.email,u.role,u.status,u.session_version AS current_version FROM remembered_logins r JOIN users u ON u.id=r.user_id WHERE r.selector=? FOR UPDATE');
            $stmt->execute([$selector]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['expires_at'] <= $now || $row['status'] !== 'active'
                || !in_array($row['role'],['admin','client'],true) || (int)$row['session_version'] !== (int)$row['current_version']) {
                $this->pdo->commit(); return null;
            }
            $hash = hash('sha256',$validator); $replacement = null;
            if (hash_equals($row['validator_hash'],$hash)) {
                $next = bin2hex(random_bytes(32));
                $stmt = $this->pdo->prepare('UPDATE remembered_logins SET previous_hash=validator_hash,validator_hash=?,rotated_at=? WHERE selector=?');
                $stmt->execute([hash('sha256',$next),$now,$selector]);
                $replacement = $selector . '.' . $next;
            } elseif (!is_string($row['previous_hash']) || !hash_equals($row['previous_hash'],$hash)
                || $now - (int)$row['rotated_at'] > 30 || $now < (int)$row['rotated_at']) {
                $this->pdo->commit(); return null;
            }
            // A parallel request using the previous token may restore its own
            // session briefly, but must not overwrite the first response's cookie.
            $this->pdo->commit();
            return ['user'=>['id'=>(int)$row['user_id'],'email'=>$row['email'],'role'=>$row['role'],'session_version'=>(int)$row['current_version']],
                'cookie'=>$replacement,'expires_at'=>(int)$row['expires_at']];
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }

    public function revoke(string $cookie): void
    {
        $parts=self::parts($cookie); if ($parts === null) { return; }
        // Selector is taken from the presented device credential, never a user ID.
        $stmt=$this->pdo->prepare('DELETE FROM remembered_logins WHERE selector=?'); $stmt->execute([$parts[0]]);
    }

    public static function cookie(string $value, int $expires): void
    {
        $params=session_get_cookie_params();
        setcookie(self::COOKIE,$value,['expires'=>$expires,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']);
    }

    private static function parts(string $cookie): ?array
    {
        return preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D',$cookie,$m) ? [$m[1],$m[2]] : null;
    }
}
