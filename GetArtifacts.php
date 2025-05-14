<?php

namespace App\Actions;

use App\Helpers\Helpers;

class GetArtifacts
{
    public function handle($workflow_run_id, $token): void
    {
        $base_url = 'https://api.github.com/repos';
        $repo = 'winlibs/winlib-builder';
        $listUrl = "{$base_url}/{$repo}/actions/runs/{$workflow_run_id}/artifacts";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $listUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: PHP Web Downloads',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_FAILONERROR    => true,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            echo "cURL Error #:" . $err;
            return;
        }
        curl_close($ch);

        $artifacts = json_decode($response, true);
        if (empty($artifacts['artifacts'])) {
            return;
        }

        $workflowRunDirectory = getenv('BUILDS_DIRECTORY') . "/winlibs/{$workflow_run_id}";
        if (is_dir($workflowRunDirectory)) {
            (new Helpers)->rmdirr($workflowRunDirectory);
        }
        umask(0);
        mkdir($workflowRunDirectory, 0777, true);

        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($artifacts['artifacts'] as $artifact) {
            $filePath = "{$workflowRunDirectory}/{$artifact['name']}.zip";
            $fp = fopen($filePath, 'w');

            $chArtifact = curl_init();
            curl_setopt_array($chArtifact, [
                CURLOPT_URL            => $artifact['archive_download_url'],
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: PHP Web Downloads',
                    "Authorization: Bearer {$token}",
                ],
            ]);

            curl_multi_add_handle($multiHandle, $chArtifact);
            $handles[] = ['handle' => $chArtifact, 'fp' => $fp];
        }

        do {
            curl_multi_exec($multiHandle, $running);
        } while ($running > 0);

        foreach ($handles as $h) {
            curl_multi_remove_handle($multiHandle, $h['handle']);
            curl_close($h['handle']);
            fclose($h['fp']);
        }

        curl_multi_close($multiHandle);
    }
}