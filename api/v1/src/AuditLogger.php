<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class AuditLogger
{
    public function __construct(
        private readonly Database $database,
        private readonly Security $security
    ) {
    }

    public function write(
        string $event,
        string $outcome,
        int $userId = 0,
        int $clientId = 0,
        string $installationId = '',
        array $metadata = []
    ): void {
        $safeMetadata = array_diff_key($metadata, array_flip([
            'password', 'access_token', 'refresh_token', 'purchase_code', 'server_key',
        ]));
        $this->database->execute(
            'INSERT INTO `Ramza_MobileAuditLog`
             (`request_id`,`event_type`,`actor_user_id`,`client_id`,`installation_hash`,`ip_hash`,`outcome`,`metadata_json`,`created_at`)
             VALUES (?,?,?,?,?,?,?,?,?)',
            'ssiissssi',
            [
                RequestContext::id(),
                substr($event, 0, 80),
                $userId,
                $clientId,
                $installationId === '' ? '' : $this->security->hash($installationId),
                $this->security->hash(RequestContext::clientIp()),
                substr($outcome, 0, 32),
                json_encode($safeMetadata, JSON_UNESCAPED_SLASHES) ?: '{}',
                time(),
            ]
        );
    }
}
