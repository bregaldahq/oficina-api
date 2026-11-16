#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Falha quando a cobertura de linhas do relatório clover fica abaixo do mínimo.
 * Uso: php bin/check-coverage.php coverage.xml 80
 */

$file    = $argv[1] ?? 'coverage.xml';
$minimum = (float)($argv[2] ?? 80);

$xml = is_file($file) ? simplexml_load_file($file) : false;
if ($xml === false) {
    fwrite(STDERR, "relatório de cobertura não encontrado ou inválido: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$total   = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];
$percent = $total === 0 ? 100.0 : $covered / $total * 100;

printf("cobertura de linhas: %.1f%% (%d de %d), mínimo %.0f%%\n", $percent, $covered, $total, $minimum);

exit($percent + 1e-9 >= $minimum ? 0 : 1);
