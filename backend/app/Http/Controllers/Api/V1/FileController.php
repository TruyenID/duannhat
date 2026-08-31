<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\FileUploadRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class FileController extends Controller
{
    use HasOrganizationContext;

    public function __construct(
        private readonly FileUploadService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/files/upload',
        summary: 'Upload a file to temp storage',
        description: 'Uploads a file to temporary storage. Returns a UUID. Files expire after 12h unless made permanent via /make-permanent.',
        tags: ['Files'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'File to upload (max 10MB)'),
                        new OA\Property(property: 'collection', type: 'string', maxLength: 50, nullable: true, description: 'Logical group (default: "default")'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'File uploaded successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/File'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation failed (file required, max 10MB)'),
        ]
    )]
    public function upload(FileUploadRequest $request): JsonResponse
    {
        $file = $this->service->uploadTemp(
            uploadedFile: $request->file('file'),
            organizationId: $this->getOrganizationId(),
            collection: $request->input('collection', 'default'),
            disk: config('filesystems.uploads', 'public'),
        );

        return (new FileResource($file))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/files/{file}',
        summary: 'Get file details',
        description: 'Returns file metadata including url, status, and expiry.',
        tags: ['Files'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'file',
                in: 'path',
                required: true,
                description: 'File UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'File details',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/File'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — file belongs to another organization'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function show(File $file): FileResource
    {
        $this->authorizeOrganization($file);

        return new FileResource($file);
    }

    #[OA\Post(
        path: '/api/v1/files/{file}/make-permanent',
        summary: 'Mark file as permanent',
        description: 'Transitions a temporary file to permanent status. Permanent files are not auto-deleted by the cleanup job. Idempotent.',
        tags: ['Files'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'file',
                in: 'path',
                required: true,
                description: 'File UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'File transitioned to permanent',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/File'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function makePermanent(File $file): FileResource
    {
        $this->authorizeOrganization($file);

        $this->service->makePermanent($file);

        return new FileResource($file);
    }

    #[OA\Delete(
        path: '/api/v1/files/{file}',
        summary: 'Delete a file',
        description: 'Soft-deletes the file record. Physical file is removed by the cleanup job after the grace period (default 72h).',
        tags: ['Files'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'file',
                in: 'path',
                required: true,
                description: 'File UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'File deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'File not found'),
        ]
    )]
    public function destroy(File $file): JsonResponse
    {
        $this->authorizeOrganization($file);

        $this->service->delete($file);

        return response()->json(null, 204);
    }
}
