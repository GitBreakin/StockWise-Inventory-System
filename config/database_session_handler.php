<?php
declare(strict_types=1);

/**
 * database_session_handler.php
 * Stores PHP sessions in Supabase PostgreSQL.
 */

final class DatabaseSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface
{   
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = db()->prepare(
                'SELECT session_data
                 FROM public.app_sessions
                 WHERE session_id = :id'
            );
            $stmt->execute(['id' => $id]);

            $data = $stmt->fetchColumn();

            return $data === false ? '' : $data;
        } catch (Throwable $e) {
            error_log('Session read failed: ' . $e->getMessage());
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = db()->prepare(
                'INSERT INTO public.app_sessions
                    (session_id, session_data, last_activity)
                 VALUES (:id, :data, :activity)
                 ON CONFLICT (session_id)
                 DO UPDATE SET
                    session_data = EXCLUDED.session_data,
                    last_activity = EXCLUDED.last_activity'
            );

            return $stmt->execute([
                'id'       => $id,
                'data'     => $data,
                'activity' => time(),
            ]);
        } catch (Throwable $e) {
            error_log('Session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = db()->prepare(
                'DELETE FROM public.app_sessions
                 WHERE session_id = :id'
            );

            return $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            error_log('Session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = db()->prepare(
                'DELETE FROM public.app_sessions
                 WHERE last_activity < :expiry'
            );

            $stmt->execute([
                'expiry' => time() - $max_lifetime,
            ]);

            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Session cleanup failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            $stmt = db()->prepare(
                'SELECT 1
                 FROM public.app_sessions
                 WHERE session_id = :id'
            );
            $stmt->execute(['id' => $id]);

            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            error_log('Session validation failed: ' . $e->getMessage());
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $stmt = db()->prepare(
                'UPDATE public.app_sessions
                 SET last_activity = :activity
                 WHERE session_id = :id'
            );

            $stmt->execute([
                'activity' => time(),
                'id'       => $id,
            ]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('Session timestamp update failed: ' . $e->getMessage());
            return false;
        }
    }
}
