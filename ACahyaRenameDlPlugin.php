<?php

/**
 * @file plugins/generic/ACahyaRenameDl/ACahyaRenameDlPlugin.php
 *
 * Copyright (c) 2026 ACahya
 * Distributed under the GNU GPL v3.
 *
 * @class ACahyaRenameDlPlugin
 * @brief Restores OJS 3.2 submission file download filename conventions for OJS 3.4 / 3.5.
 */

namespace APP\plugins\generic\ACahyaRenameDl;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\ACahyaRenameDl\classes\ACahyaRenameDlSettingsForm;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\reviewAssignment\ReviewAssignment;

class ACahyaRenameDlPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Hook into file formatting (backend grids, workflow, zip archives)
                if (class_exists(Hook::class)) {
                    Hook::add('File::formatFilename', [$this, 'formatFilenameHandler']);
                    Hook::add('File::download', [$this, 'downloadHandler']);
                } else {
                    \HookRegistry::register('File::formatFilename', [$this, 'formatFilenameHandler']);
                    \HookRegistry::register('File::download', [$this, 'downloadHandler']);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        try {
            $name = __('plugins.generic.acahyarenamedl.displayName');
            if ($name && !str_starts_with($name, '##')) {
                return $name;
            }
        } catch (\Throwable $e) {}

        return 'ACahya Submission File Download Renamer';
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        try {
            $desc = __('plugins.generic.acahyarenamedl.description');
            if ($desc && !str_starts_with($desc, '##')) {
                return $desc;
            }
        } catch (\Throwable $e) {}

        return 'Restores classic OJS 3.2 submission file download naming convention or custom patterns when downloading files.';
    }

    /**
     * @copydoc LazyLoadPlugin::getEnabled()
     */
    public function getEnabled($contextId = null)
    {
        if ($contextId === null) {
            try {
                $request = Application::get()->getRequest();
                $router = $request ? $request->getRouter() : null;
                $context = ($router && $request) ? $request->getContext() : null;
                $contextId = $context ? $context->getId() : Application::SITE_CONTEXT_ID;
            } catch (\Throwable $e) {
                $contextId = Application::SITE_CONTEXT_ID;
            }
        }
        $enabled = $this->getSetting($contextId, 'enabled');
        // Default to enabled out-of-the-box if not explicitly disabled
        if ($enabled === null) {
            return true;
        }
        return (bool) $enabled;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $settingsAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );
        array_unshift($actions, $settingsAction);

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new ACahyaRenameDlSettingsForm($this);
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                }
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

    /**
     * Hook handler for File::formatFilename
     *
     * @param string $hookName
     * @param array $args [&$newFilename, $path, $filename]
     * @return bool
     */
    public function formatFilenameHandler(string $hookName, array $args): bool
    {
        $path = $args[1] ?? '';
        $originalFilename = $args[2] ?? '';

        if (empty($path)) {
            return false;
        }

        $contextId = $this->resolveContextId($path);
        if (!$this->getEnabled($contextId)) {
            return false;
        }

        // Check plugin settings toggle
        $pluginSettingEnabled = $this->getSetting($contextId, 'enablePlugin');
        if ($pluginSettingEnabled !== null && !$pluginSettingEnabled) {
            return false;
        }

        $meta = $this->resolveFileMetadata($path, $originalFilename);
        if (!$meta) {
            return false;
        }

        // Check galley setting (file stage 10 = SUBMISSION_FILE_PROOF)
        $applyToGalleys = $this->getSetting($contextId, 'applyToGalleys');
        if ($applyToGalleys === null) {
            $applyToGalleys = true;
        }
        if ((int)$meta['fileStage'] === 10 && !$applyToGalleys) {
            return false;
        }

        $formattedName = $this->formatName($meta, $contextId);
        if (!empty($formattedName)) {
            $args[0] = $formattedName;
        }

        return false;
    }

    /**
     * Hook handler for File::download
     * Ensures clean HTTP headers, UTF-8 filename encoding, and RFC 5987 streaming.
     *
     * @param string $hookName
     * @param array $args [$file, &$filename, $inline]
     * @return bool
     */
    public function downloadHandler(string $hookName, array $args): bool
    {
        $file = $args[0];
        $filename = &$args[1];
        $inline = (bool)($args[2] ?? false);

        $path = is_object($file) ? ($file->path ?? '') : (string)$file;
        if (empty($path)) {
            return false;
        }

        $contextId = $this->resolveContextId($path);
        if (!$this->getEnabled($contextId)) {
            return false;
        }

        // Check plugin settings toggle
        $pluginSettingEnabled = $this->getSetting($contextId, 'enablePlugin');
        if ($pluginSettingEnabled !== null && !$pluginSettingEnabled) {
            return false;
        }

        // If filename is not yet formatted, format it now
        $meta = $this->resolveFileMetadata($path, $filename);
        if ($meta) {
            $applyToGalleys = $this->getSetting($contextId, 'applyToGalleys');
            if ($applyToGalleys === null) {
                $applyToGalleys = true;
            }
            if ((int)$meta['fileStage'] !== 10 || $applyToGalleys) {
                $customFormatted = $this->formatName($meta, $contextId);
                if (!empty($customFormatted)) {
                    $filename = $customFormatted;
                }
            }
        }

        // Stream file directly with clean Content-Disposition headers
        $fileService = app()->has('file') ? app()->get('file') : null;
        if (!$fileService || !$fileService->fs || !$fileService->fs->has($path)) {
            return false; // let core handle 404
        }

        $fs = $fileService->fs;

        // Clean any preceding output buffers to avoid corrupted file contents
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $mimetype = is_object($file) && !empty($file->mimetype)
            ? $file->mimetype
            : (method_exists($fs, 'mimeType') ? ($fs->mimeType($path) ?? 'application/octet-stream') : 'application/octet-stream');

        $filesize = method_exists($fs, 'fileSize')
            ? $fs->fileSize($path)
            : (method_exists($fs, 'getSize') ? $fs->getSize($path) : 0);

        $cleanFilename = str_replace(['"', "\r", "\n", ';'], '', $filename);
        $encodedFilename = rawurlencode($cleanFilename);

        header("Content-Type: {$mimetype}");
        if ($filesize > 0) {
            header("Content-Length: {$filesize}");
        }
        header('Accept-Ranges: none');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $cleanFilename . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Cache-Control: private, must-revalidate');
        header('Pragma: public');

        $stream = $fs->readStream($path);
        if ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        exit();

        return true;
    }

    /**
     * Resolves context ID from path or current request
     */
    protected function resolveContextId(string $path): int
    {
        if (preg_match('/(?:journals|contexts)\/(\d+)\//', $path, $matches)) {
            return (int)$matches[1];
        }

        try {
            $request = Application::get()->getRequest();
            $router = $request ? $request->getRouter() : null;
            $context = ($router && $request) ? $request->getContext() : null;
            return $context ? $context->getId() : Application::SITE_CONTEXT_ID;
        } catch (\Throwable $e) {
            return Application::SITE_CONTEXT_ID;
        }
    }

    /**
     * Resolve submission file metadata from disk path
     */
    protected function resolveFileMetadata(string $path, string $fallbackFilename): ?array
    {
        try {
            $fileRecord = DB::table('files')->where('path', $path)->first();
            $fileId = $fileRecord ? (int)$fileRecord->file_id : null;

            $subFileRow = null;
            if ($fileId) {
                $subFileRow = DB::table('submission_files as sf')
                    ->leftJoin('submission_file_revisions as sfr', 'sf.submission_file_id', '=', 'sfr.submission_file_id')
                    ->where('sf.file_id', $fileId)
                    ->orWhere('sfr.file_id', $fileId)
                    ->select([
                        'sf.submission_file_id',
                        'sf.submission_id',
                        'sf.genre_id',
                        'sf.file_stage',
                        'sf.created_at',
                        'sf.uploader_user_id',
                        'sfr.revision_id',
                        DB::raw('COALESCE(sfr.file_id, sf.file_id) as actual_file_id')
                    ])
                    ->first();
            }

            // Fallback for older OJS DB schemas
            if (!$subFileRow) {
                if (Schema::hasColumn('submission_files', 'path')) {
                    $subFileRow = DB::table('submission_files')->where('path', $path)->first();
                }
            }

            // Fallback extracting submission ID from directory path
            if (!$subFileRow && preg_match('/(?:articles|submissions)\/(\d+)\//', $path, $m)) {
                $subId = (int)$m[1];
                $subFileRow = DB::table('submission_files')
                    ->where('submission_id', $subId)
                    ->orderBy('submission_file_id', 'desc')
                    ->first();
            }

            if (!$subFileRow) {
                return null;
            }

            $submissionId = (int)$subFileRow->submission_id;
            $submissionFileId = (int)$subFileRow->submission_file_id;
            $fileStage = (int)$subFileRow->file_stage;
            $genreId = !empty($subFileRow->genre_id) ? (int)$subFileRow->genre_id : null;
            $date = date('Ymd', strtotime($subFileRow->created_at ?? 'now'));
            $revision = !empty($subFileRow->revision_id) ? (int)$subFileRow->revision_id : 1;
            $actualFileId = !empty($subFileRow->actual_file_id) ? (int)$subFileRow->actual_file_id : ($fileId ?: $submissionFileId);

            // Genre Name
            $genreName = $this->resolveGenreName($genreId);

            // Author Name (anonymized if double-blind)
            $authorName = $this->resolveAuthorName($submissionId);

            // Extension
            $ext = strtolower(pathinfo($fallbackFilename, PATHINFO_EXTENSION));
            if (!$ext) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            }

            // Original Filename
            $originalName = pathinfo($fallbackFilename, PATHINFO_FILENAME);
            if (!$originalName || preg_match('/^[a-f0-9]{10,}$/i', $originalName)) {
                $dbName = DB::table('submission_file_settings')
                    ->where('submission_file_id', $submissionFileId)
                    ->where('setting_name', 'name')
                    ->value('setting_value');
                if ($dbName) {
                    $originalName = pathinfo($dbName, PATHINFO_FILENAME);
                }
            }

            return [
                'submissionId' => $submissionId,
                'submissionFileId' => $submissionFileId,
                'fileId' => $actualFileId,
                'revision' => $revision,
                'fileStage' => $fileStage,
                'date' => $date,
                'genre' => $genreName,
                'authorName' => $authorName,
                'originalName' => $originalName ?: 'file',
                'ext' => $ext ?: 'dat',
            ];
        } catch (\Throwable $e) {
            error_log('ACahyaRenameDl: Error resolving file metadata: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolves the localized Genre name
     */
    protected function resolveGenreName(?int $genreId): string
    {
        if (!$genreId) {
            return 'Document';
        }

        $genreName = '';
        try {
            $genreDao = DAORegistry::getDAO('GenreDAO');
            if ($genreDao) {
                $genre = $genreDao->getById($genreId);
                if ($genre) {
                    $genreName = $genre->getLocalizedName();
                }
            }
        } catch (\Throwable $e) {}

        if (!$genreName) {
            $locale = 'en';
            try {
                $request = Application::get()->getRequest();
                $locale = $request ? ($request->getLocale() ?? 'en') : 'en';
            } catch (\Throwable $e) {}

            $genreRow = DB::table('genre_settings')
                ->where('genre_id', $genreId)
                ->where('setting_name', 'name')
                ->where('locale', $locale)
                ->first();
            if (!$genreRow) {
                $genreRow = DB::table('genre_settings')
                    ->where('genre_id', $genreId)
                    ->where('setting_name', 'name')
                    ->first();
            }
            if ($genreRow) {
                $genreName = $genreRow->setting_value;
            }
        }

        // Translate locale keys like ##default.genres.article##
        if (str_starts_with($genreName, '##') && str_ends_with($genreName, '##')) {
            $cleanKey = trim($genreName, '#');
            try {
                $translated = __($cleanKey);
                if ($translated !== $cleanKey) {
                    $genreName = $translated;
                } else {
                    $genreName = 'Article Text';
                }
            } catch (\Throwable $e) {
                $genreName = 'Article Text';
            }
        }

        return $genreName ?: 'Article Text';
    }

    /**
     * Resolves the primary author name (respecting double-blind review anonymity)
     */
    protected function resolveAuthorName(int $submissionId): string
    {
        try {
            $request = Application::get()->getRequest();
            $router = $request ? $request->getRouter() : null;
            if ($router && $request) {
                $reviewAssignmentId = $request->getUserVar('reviewAssignmentId');
                if ($reviewAssignmentId && class_exists(Repo::class)) {
                    $reviewAssignment = Repo::reviewAssignment()->get((int)$reviewAssignmentId);
                    if ($reviewAssignment && $reviewAssignment->getReviewMethod() == ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS) {
                        return 'Author'; // Do not disclose author in double-blind review
                    }
                }
            }
        } catch (\Throwable $e) {}

        try {
            $author = DB::table('authors as a')
                ->join('publications as p', 'a.publication_id', '=', 'p.publication_id')
                ->where('p.submission_id', $submissionId)
                ->leftJoin('author_settings as as_family', function ($join) {
                    $join->on('a.author_id', '=', 'as_family.author_id')
                        ->where('as_family.setting_name', '=', 'familyName');
                })
                ->leftJoin('author_settings as as_given', function ($join) {
                    $join->on('a.author_id', '=', 'as_given.author_id')
                        ->where('as_given.setting_name', '=', 'givenName');
                })
                ->orderBy('p.version', 'desc')
                ->orderBy('a.seq', 'asc')
                ->select([
                    'as_family.setting_value as family_name',
                    'as_given.setting_value as given_name'
                ])
                ->first();

            if ($author) {
                return $author->family_name ?: ($author->given_name ?: '');
            }
        } catch (\Throwable $e) {}

        return '';
    }

    /**
     * Formats filename using configured pattern and space handling
     */
    protected function formatName(array $meta, int $contextId): string
    {
        $pattern = $this->getSetting($contextId, 'namingPattern');
        if (empty($pattern)) {
            // OJS 3.2 Classic convention (exact match with OjtFileDownload):
            // submissionId-genre-fileId-revision-fileStage-date.ext
            $pattern = '{$submissionId}-{$genre}-{$fileId}-{$revision}-{$fileStage}-{$date}.{$ext}';
        }

        $replaceMap = [
            '{$submissionId}' => $meta['submissionId'],
            '{$genre}' => $meta['genre'],
            '{$fileId}' => $meta['fileId'],
            '{$submissionFileId}' => $meta['submissionFileId'],
            '{$revision}' => $meta['revision'],
            '{$fileStage}' => $meta['fileStage'],
            '{$date}' => $meta['date'],
            '{$originalName}' => $meta['originalName'],
            '{$authorName}' => $meta['authorName'],
            '{$ext}' => $meta['ext'],
        ];

        $formatted = str_replace(array_keys($replaceMap), array_values($replaceMap), $pattern);

        // Sanitize spaces
        $replaceSpaces = $this->getSetting($contextId, 'replaceSpaces') ?: 'hyphen';
        if ($replaceSpaces === 'hyphen') {
            $formatted = preg_replace('/[\s\-]+/', '-', $formatted);
        } elseif ($replaceSpaces === 'underscore') {
            $formatted = preg_replace('/\s+/', '_', $formatted);
        }

        // Strip disallowed characters for safe cross-platform file saving
        $formatted = preg_replace('/[\\\\\/:*?"<>|#%&{}\\$!\'=@+`~]/', '', $formatted);

        // Ensure proper extension
        $extSuffix = '.' . $meta['ext'];
        if (!str_ends_with(strtolower($formatted), strtolower($extSuffix))) {
            $formatted .= $extSuffix;
        }

        return $formatted;
    }
}
