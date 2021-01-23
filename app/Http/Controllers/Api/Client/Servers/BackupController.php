<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Permission;
use Illuminate\Validation\UnauthorizedException;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\DownloadLinkService;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Transformers\Api\Client\BackupTransformer;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\Backups\StoreBackupRequest;

class BackupController extends ClientApiController
{
    /**
     * @var \Pterodactyl\Services\Backups\InitiateBackupService
     */
    private $initiateBackupService;

    /**
     * @var \Pterodactyl\Services\Backups\DeleteBackupService
     */
    private $deleteBackupService;

    /**
     * @var \Pterodactyl\Services\Backups\DownloadLinkService
     */
    private $downloadLinkService;

    /**
     * @var \Pterodactyl\Repositories\Wings\DaemonBackupRepository
     */
    private $repository;

    /**
     * BackupController constructor.
     */
    public function __construct(
        DaemonBackupRepository $repository,
        DeleteBackupService $deleteBackupService,
        InitiateBackupService $initiateBackupService,
        DownloadLinkService $downloadLinkService
    ) {
        parent::__construct();

        $this->repository = $repository;
        $this->initiateBackupService = $initiateBackupService;
        $this->deleteBackupService = $deleteBackupService;
        $this->downloadLinkService = $downloadLinkService;
    }

    /**
     * Returns all of the backups for a given server instance in a paginated
     * result set.
     *
     * @return array
     */
    public function index(Request $request, Server $server)
    {
        if (! $request->user()->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw new UnauthorizedException;
        }

        $limit = min($request->query('per_page') ?? 20, 50);

        return $this->fractal->collection($server->backups()->paginate($limit))
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Starts the backup process for a server.
     *
     * @return array
     *
     * @throws \Exception|\Throwable
     */
    public function store(StoreBackupRequest $request, Server $server)
    {
        /** @var \Pterodactyl\Models\Backup $backup */
        $backup = $server->audit(AuditLog::SERVER__BACKUP_STARTED, function (AuditLog $model, Server $server) use ($request) {
            $backup = $this->initiateBackupService
                ->setIgnoredFiles(
                    explode(PHP_EOL, $request->input('ignored') ?? '')
                )
                ->handle($server, $request->input('name'));

            $model->metadata = ['backup_uuid' => $backup->uuid];

            return $backup;
        });

        return $this->fractal->item($backup)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Returns information about a single backup.
     *
     * @return array
     */
    public function view(Request $request, Server $server, Backup $backup)
    {
        if (! $request->user()->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw new UnauthorizedException;
        }

        return $this->fractal->item($backup)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Deletes a backup from the panel as well as the remote source where it is currently
     * being stored.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Throwable
     */
    public function restore(Request $request, Server $server, Backup $backup)
    {
        if (! $request->user()->can(Permission::ACTION_BACKUP_RESTORE, $server)) {
            throw new UnauthorizedException;
        }

        // Cannot restore a backup unless a server is fully installed and not currently
        // processing a different backup restoration request.
        if (! is_null($server->status)) {
            throw new BadRequestHttpException('This server is not currently in a state that allows for a backup to be restored.');
        }

        if (!$backup->is_successful && !$backup->completed_at) {
            throw new BadRequestHttpException('This backup cannot be restored at this time: not completed or failed.');
        }

        $server->audit(AuditLog::SERVER__BACKUP_RESTORE_STARTED, function (AuditLog $audit, Server $server) use ($backup, $request) {
            $audit->metadata = ['backup_uuid' => $backup->uuid];

            // If the backup is for an S3 file we need to generate a unique Download link for
            // it that will allow Wings to actually access the file.
            if ($backup->disk === Backup::ADAPTER_AWS_S3) {
                $url = $this->downloadLinkService->handle($backup, $request->user());
            }

            // Update the status right away for the server so that we know not to allow certain
            // actions against it via the Panel API.
            $server->update(['status' => Server::STATUS_RESTORING_BACKUP]);

            $this->repository->setServer($server)->restore($backup, $url ?? null, $request->input('truncate') === 'true');
        });

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
