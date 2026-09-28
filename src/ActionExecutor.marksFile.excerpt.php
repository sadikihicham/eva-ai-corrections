        try {
            $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
            $backend->deleteCard($found['bookId'], $found['uri']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Contact delete failed: ' . $e->getMessage()];
        }
        return ['ok' => true, 'result' => 'Deleted contact'];
    }

    // ---- Marker für "von der KI erstellt" ----

    private function marksFile(string $userId): \OCP\Files\SimpleFS\ISimpleFile {
        // IAppDataFactory wird lazy geholt statt per Konstruktor injiziert:
        // die Aufloesung blockiert im CLI/taskprocessing-Worker.
        // CORRIGÉ 28/09/2026 : mauvais espace de noms (la classe
        // OCP\AppFramework\Services\IAppDataFactory n'existe pas dans
        // Nextcloud 34 → "Could not resolve …" à chaque create_file/mark).
        // Le bon espace de noms, déjà utilisé ailleurs dans eva_ai
        // (UserDataService.php, DirtyIndexStore.php) :
        $appdata = \OC::$server->get(\OCP\Files\AppData\IAppDataFactory::class)->get('eva_ai');
        try {
            $dir = $appdata->getFolder('ai-marks');
        } catch (\OCP\Files\NotFoundException $e) {
            $dir = $appdata->newFolder('ai-marks');
        }
        // Collision-free per-user namespace (SHA-256 of the exact user ID).
        // Legacy lossy-slug folders are migrated lazily so existing markers
        // are preserved (Issue #8).
        $ns = substr(hash('sha256', $userId), 0, 40);
        try {
            $uid = $dir->getFolder($ns);
        } catch (\OCP\Files\NotFoundException $e) {
            $legacy = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';
            try {
                $legacyFolder = $dir->getFolder($legacy);
                $uid = $dir->newFolder($ns);
