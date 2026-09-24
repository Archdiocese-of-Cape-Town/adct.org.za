<?php

namespace ADCT\ParishIntake\Export;

use ADCT\ParishIntake\Database\Schema;

final class StaticReportGenerator
{
    private Schema $schema;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function generate(int $limit = 50): ?array
    {
        if (! function_exists('wp_upload_dir')) {
            return null;
        }

        $uploads = wp_upload_dir();

        if (! empty($uploads['error'])) {
            return null;
        }

        $dir = trailingslashit($uploads['basedir']) . 'adct-parish-intake';
        $url = trailingslashit($uploads['baseurl']) . 'adct-parish-intake/latest-report.html';
        $path = trailingslashit($dir) . 'latest-report.html';

        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            return null;
        }

        $rows = $this->schema->fetchRecent($limit);
        $html = $this->renderHtml($rows);

        if (file_put_contents($path, $html) === false) {
            return null;
        }

        return ['path' => $path, 'url' => $url];
    }

    private function renderHtml(array $rows): string
    {
        $items = '';

        foreach ($rows as $row) {
            $items .= sprintf(
                "<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><pre>%s</pre></td></tr>",
                (int) $row['id'],
                htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $row['sender_email'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $row['classification'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $row['parish_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $row['extracted_payload'], ENT_QUOTES, 'UTF-8')
            );
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>ADCT Parish Intake Snapshot</title><style>body{font-family:Arial,sans-serif;margin:2rem;}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:.5rem;vertical-align:top}pre{white-space:pre-wrap;margin:0}</style></head><body><h1>ADCT Parish Intake Snapshot</h1><p>Generated at ' . htmlspecialchars(gmdate('c'), ENT_QUOTES, 'UTF-8') . '</p><table><thead><tr><th>ID</th><th>Created</th><th>Sender</th><th>Classification</th><th>Title</th><th>Parish</th><th>Extracted fields</th></tr></thead><tbody>' . $items . '</tbody></table></body></html>';
    }
}
