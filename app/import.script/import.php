<?php

// Composer autoloader (for Webklex/php-imap and others)
require_once __DIR__ . '/../../vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;

// Always load config from the script's directory regardless of current working directory
include __DIR__ . '/import.conf.php';

// ---- Logging setup -------------------------------------------------------
// Supported levels: 'error' (0), 'warn' (1), 'info' (2), 'debug' (3)
// Configurable via import.conf.php and CLI flags (-q/--quiet, -v, -vv, --log-level=LEVEL, --log-file=PATH, --no-stdout)

// Defaults if not provided by config
if (!isset($logLevel)) { $logLevel = 'info'; }
if (!isset($logFile)) { $logFile = __DIR__ . '/import.log'; }
if (!isset($logToStdout)) { $logToStdout = true; }

// Parse CLI flags to override logging options
if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if ($arg === '-q' || $arg === '--quiet') { $logLevel = 'error'; }
        if ($arg === '-v') { $logLevel = 'debug'; } // single -v => be verbose (debug)
        if ($arg === '-vv') { $logLevel = 'debug'; }
        if (strpos($arg, '--log-level=') === 0) { $logLevel = substr($arg, 12); }
        if (strpos($arg, '--log-file=') === 0) { $logFile = substr($arg, 11); }
        if ($arg === '--no-stdout') { $logToStdout = false; }
    }
}

// internal numeric level
$__LOG_LEVELS = ['error' => 0, 'warn' => 1, 'info' => 2, 'debug' => 3];
$__LOG_LEVEL = $__LOG_LEVELS[strtolower((string)$logLevel)] ?? 2;

resetLog($logFile);

// Stats for final summary
$STATS = [
    'mode' => null,
    'messages_seen' => 0,
    'attachments_seen' => 0,
    'xml_parsed' => 0,
    'xml_failed' => 0,
    'reports_inserted' => 0,
    'reports_skipped_existing' => 0,
    'records_inserted' => 0,
    'messages_moved' => 0,
    'errors' => 0,
];

try {
    $dbc = new PDO("mysql:dbname=$dbname;host=$dbhost", $dbuser, $dbpass);
} catch (PDOException $e) {
    log_error('Database connection failed: ' . $e->getMessage());
    emitSummaryAndExit(1);
}

// if table doesn't exist, create one
if ($dbc->query('show tables LIKE \'report\'')->fetch() === false) {
    log_info('Table report doesn\'t exist... Creating...');
    if (!createTableReport()) {
        log_error('Couldn\'t create table \'report\'.');        
        emitSummaryAndExit(1);
    }
}

// if table doesn't exist, create one
if ($dbc->query('show tables LIKE \'rptrecord\'')->fetch() === false) {
    log_info('Table rptrecord doesn\'t exist... Creating...');
    if (!createTableRptrecord()) {
        log_error('Couldn\'t create table \'rptrecord\'.');        
        emitSummaryAndExit(1);
    }
}

set_time_limit(0);

if (!isset($source)) { $source = 'imap'; }

if ($source === 'local') {
    $STATS['mode'] = 'local';
    log_info('Starting import in LOCAL mode');
    log_debug('localPath=' . ($localPath ?? ''));    
    // Local directory import mode: process .zip, .gz, .xml files from $localPath
    if (!isset($localPath)) {
        log_error('Local import path not configured. Set $localPath in import.conf.php');
        emitSummaryAndExit(1);
    }
    if (!is_dir($localPath)) {
        log_error('Local import path does not exist: ' . $localPath);
        emitSummaryAndExit(1);
    }
    $dir = new DirectoryIterator($localPath);
    foreach ($dir as $fileinfo) {
        if ($fileinfo->isDot() || !$fileinfo->isFile()) continue;
        $filename = $fileinfo->getFilename();
        $lower = strtolower($filename);
        $path = $fileinfo->getPathname();
        log_info('Processing local file: ' . $filename);
        $xmls = [];
        if (substr($lower, -4) === '.xml') {
            $xmlRaw = file_get_contents($path);
            $xmls[] = [$xmlRaw, $filename];
        } elseif (substr($lower, -3) === '.gz') {
            $data = file_get_contents($path);
            $xmlRaw = gzdecode($data);
            if ($xmlRaw !== false) {
                $xmls[] = [$xmlRaw, $filename];
            }
        } elseif (substr($lower, -4) === '.zip') {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    $entryLower = strtolower($entryName);
                    if (substr($entryLower, -4) === '.xml') {
                        $xmlRaw = $zip->getFromIndex($i);
                        if ($xmlRaw !== false) {
                            $xmls[] = [$xmlRaw, $entryName];
                        }
                    } elseif (substr($entryLower, -3) === '.gz') {
                        $gzRaw = $zip->getFromIndex($i);
                        if ($gzRaw !== false) {
                            $xmlRaw = gzdecode($gzRaw);
                            if ($xmlRaw !== false) {
                                $xmls[] = [$xmlRaw, $entryName];
                            }
                        }
                    }
                }
                $zip->close();
            } else {
                log_warn('    Unable to open zip: ' . $filename);
            }
        } else {
            log_debug('    Skipping unsupported file: ' . $filename);
        }
        foreach ($xmls as [$xmlRaw, $entryName]) {
            $STATS['attachments_seen']++;
            $xml = @simplexml_load_string($xmlRaw);
            if ($xml === false) {
                $STATS['xml_failed']++;
                log_warn('    Xml load failed in ' . $entryName . ' skipping...');
                continue;
            }
            $xmlData = readXmlData($xml);
            storeXmlData($xmlData,$xmlRaw);
            $STATS['xml_parsed']++;
        }
    }
    emitSummaryAndExit(0);
}

/* IMAP import mode using Webklex/php-imap (no ext-imap required) */

// Parse legacy $host string like 'localhost:143/imap/tls/novalidate-cert'
$imapHost = 'localhost';
$imapPort = 143;
$encryption = null; // 'ssl' | 'tls' | null
$validateCert = true;
$protocol = 'imap';
if (isset($host) && is_string($host)) {
    // remove surrounding braces if passed like "{host:port/imap/...}"
    $trim = trim($host);
    $trim = preg_replace('/^[{](.*)[}]$/', '$1', $trim);
    $parts = explode('/', $trim);
    // host:port part
    if (isset($parts[0]) && $parts[0] !== '') {
        $hp = explode(':', $parts[0], 2);
        $imapHost = $hp[0];
        if (isset($hp[1]) && ctype_digit($hp[1])) {
            $imapPort = (int)$hp[1];
        }
    }
    foreach ($parts as $p) {
        $p = strtolower($p);
        if ($p === 'imap' || $p === 'imap4') { $protocol = 'imap'; }
        if ($p === 'pop3' || $p === 'pop') { $protocol = 'pop3'; }
        if ($p === 'ssl') { $encryption = 'ssl'; if ($imapPort === 143) $imapPort = 993; }
        if ($p === 'tls') { $encryption = 'tls'; }
        if ($p === 'novalidate-cert') { $validateCert = false; }
    }
}

try {
    $cm = new ClientManager();
    $client = $cm->make([
        'host'          => $imapHost,
        'port'          => $imapPort,
        'encryption'    => $encryption,      // 'ssl', 'tls', or null
        'validate_cert' => $validateCert,
        'username'      => $username,
        'password'      => $password,
        'protocol'      => $protocol,         // 'imap'
        'options'       => [
            'fetch_order' => 'desc',          // newest first
        ],
    ]);
    log_info('Connecting to IMAP ' . $imapHost . ':' . $imapPort . ' protocol=' . $protocol . ' enc=' . ($encryption ?: 'none'));
    $client->connect();
} catch (Throwable $e) {
    log_error('Cannot connect to Email: ' . $e->getMessage());
    emitSummaryAndExit(1);
}

try {
    $inbox = $client->getFolder($folderInbox);
} catch (Throwable $e) {
    log_error('Cannot open folder ' . $folderInbox . ': ' . $e->getMessage());
    emitSummaryAndExit(1);
}

$STATS['mode'] = 'imap';
$messages = $inbox->messages()->all()->get();
log_info('IMAP message count: ' . $messages->count());

if ($messages->count() > 0) {
    foreach ($messages as $message) {
        $STATS['messages_seen']++;
        $subject = $message->getSubject();
        log_info('Processing message...  Subject:"' . $subject . '"');

        // iterate attachments (ZIP/GZ expected)
        $attachments = $message->getAttachments();
        foreach ($attachments as $att) {
            $STATS['attachments_seen']++;
            $filename = $att->getName();
            if (!$filename) { $filename = 'attachment.bin'; }
            log_info('  Processing attachment: ' . $filename);

            $data = $att->getContent();

            if (preg_match('/\.zip$/i', $filename)) {
                // ZIP: save to temp and read first entry
                $tmp = tempnam(sys_get_temp_dir(), 'dmarc_zip_');
                file_put_contents($tmp, $data);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true && $zip->numFiles > 0) {
                    $xmlFilename = $zip->getNameIndex(0);
                    $xmlRaw = $zip->getFromIndex(0);
                    $zip->close();
                    @unlink($tmp);
                    $xml = @simplexml_load_string($xmlRaw);
                    if ($xml === false) {
                        $STATS['xml_failed']++;
                        log_warn('    Xml load failed attachment ' . $filename . ' skipping...');
                        continue;
                    }
                    $xmlData = readXmlData($xml);
                    storeXmlData($xmlData,$xmlRaw);
                    $STATS['xml_parsed']++;
                } else {
                    @unlink($tmp);
                    log_warn('    Unable to open zip: ' . $filename);
                    continue;
                }
            } elseif (preg_match('/\.gz$/i', $filename)) {
                $xmlRaw = gzdecode($data);
                $xml = @simplexml_load_string($xmlRaw);
                if ($xml === false) {
                    $STATS['xml_failed']++;
                    log_warn('    Xml load failed attachment ' . $filename . ' skipping...');
                    continue;
                }
                $xmlData = readXmlData($xml);
                storeXmlData($xmlData,$xmlRaw);
                $STATS['xml_parsed']++;
            } elseif (preg_match('/\.xml$/i', $filename)) {
                $xmlRaw = $data;
                $xml = @simplexml_load_string($xmlRaw);
                if ($xml === false) {
                    $STATS['xml_failed']++;
                    log_warn('    Xml load failed attachment ' . $filename . ' skipping...');
                    continue;
                }
                $xmlData = readXmlData($xml);
                storeXmlData($xmlData,$xmlRaw);
                $STATS['xml_parsed']++;
            } else {
                log_debug('    Unknown attachment ' . $filename . ' skipping...');
                continue;
            }
        }

        // Move message to processed folder if configured
        if (!empty($folderProcessed)) {
            try {
                $message->move($folderProcessed);
                $STATS['messages_moved']++;
            } catch (Throwable $e) {
                // Try to create the folder and move again
                try {
                    $client->createFolder($folderProcessed);
                    $message->move($folderProcessed);
                    $STATS['messages_moved']++;
                } catch (Throwable $e2) {
                    log_warn('    Unable to move message to ' . $folderProcessed . ': ' . $e2->getMessage());
                }
            }
        }
    }
}

emitSummaryAndExit(0);

function readXmlData($xml) {
    $xmlData['dateFrom'] = (int)$xml->report_metadata->date_range->begin;
    $xmlData['dateTo'] = (int)$xml->report_metadata->date_range->end;
    $xmlData['organization'] = (string)$xml->report_metadata->org_name;
    $xmlData['reportId'] = (string)$xml->report_metadata->report_id;
    $xmlData['email'] = (string)$xml->report_metadata->email;
    $xmlData['extraContactInfo'] = (string)$xml->report_metadata->extra_contact_info;
    $xmlData['domain'] = (string)$xml->policy_published->domain;
    $xmlData['policy_adkim'] = (string)$xml->policy_published->adkim;
    $xmlData['policy_aspf'] = (string)$xml->policy_published->aspf;
    $xmlData['policy_p'] = (string)$xml->policy_published->p;
    $xmlData['policy_sp'] = (string)$xml->policy_published->sp;
    $xmlData['policy_pct'] = (string)$xml->policy_published->pct;

    $recordRow = 0;
    foreach ($xml->record as $record) {
        $xmlData['record'][$recordRow]['ip'] = (string)$record->row->source_ip;
        $xmlData['record'][$recordRow]['count'] = (int)$record->row->count;
        $xmlData['record'][$recordRow]['disposition'] = (string)$record->row->policy_evaluated->disposition;
        $xmlData['record'][$recordRow]['dkim_align'] = (string)$record->row->policy_evaluated->dkim;
        $xmlData['record'][$recordRow]['spf_align'] = (string)$record->row->policy_evaluated->spf;
        $xmlData['record'][$recordRow]['hfrom'] = (string)$record->identifiers->header_from;
        $dkimDomain = $dkimResult = $dkimSelector = '';
        foreach ($record->auth_results->dkim as $dkim) {
            $dkimDomain .= (string)$dkim->domain . '/';
            $dkimResult .= (string)$dkim->result . '/';
            $dkimSelector .= (string)$dkim->selector . '/';
        }
        $xmlData['record'][$recordRow]['dkimDomain'] = trim($dkimDomain,'/');
        $xmlData['record'][$recordRow]['dkimResult'] = trim($dkimResult,'/');
        $xmlData['record'][$recordRow]['dkimSelector'] = trim($dkimSelector,'/');
        $xmlData['record'][$recordRow]['spfDomain'] = (string)$record->auth_results->spf->domain;
        $xmlData['record'][$recordRow]['spfResult'] = (string)$record->auth_results->spf->result;
        $recordRow++;
    }
    return $xmlData;
}

function storeXmlData($xmlData,$xmlRaw) {
    global $dbc, $STATS;
    // check if report already exists
    $queryCheck = $dbc->prepare('SELECT serial AS count FROM report WHERE reportid=? AND domain=?');
    $parametersCheck[] = $xmlData['reportId'];
    $parametersCheck[] = $xmlData['domain'];
    $queryCheck->execute($parametersCheck);
    if ($queryCheck->fetch()) {
        $STATS['reports_skipped_existing']++;
        log_debug('    Report already exists. reportId: ' . $xmlData['reportId'] . ' domain: ' . $xmlData['domain'] . ' skipping...');
        return;
    }

    // insert report
    $queryReport = $dbc->prepare('INSERT INTO report(serial,mindate,maxdate,domain,org,reportid,email,extra_contact_info,policy_adkim, policy_aspf, policy_p, policy_sp, policy_pct, raw_xml)
        VALUES(NULL,FROM_UNIXTIME(?),FROM_UNIXTIME(?),?,?,?,?,?,?,?,?,?,?,?)');
    $parametersReport[] = $xmlData['dateFrom'];
    $parametersReport[] = (strlen($xmlData['dateTo']) > 0) ? $xmlData['dateTo'] : NULL;
    $parametersReport[] = $xmlData['domain'];
    $parametersReport[] = $xmlData['organization'];
    $parametersReport[] = $xmlData['reportId'];
    $parametersReport[] = (strlen($xmlData['email']) > 0) ? $xmlData['email'] : NULL;
    $parametersReport[] = (strlen($xmlData['extraContactInfo']) > 0) ? $xmlData['extraContactInfo'] : NULL;;
    $parametersReport[] = (strlen($xmlData['policy_adkim']) > 0) ? $xmlData['policy_adkim'] : NULL;
    $parametersReport[] = (strlen($xmlData['policy_aspf']) > 0) ? $xmlData['policy_aspf'] : NULL;
    $parametersReport[] = (strlen($xmlData['policy_p']) > 0) ? $xmlData['policy_p'] : NULL;
    $parametersReport[] = (strlen($xmlData['policy_sp']) > 0) ? $xmlData['policy_sp'] : NULL;
    $parametersReport[] = (strlen($xmlData['policy_pct']) > 0) ? $xmlData['policy_pct'] : NULL;
    $parametersReport[] = base64_encode(gzencode($xmlRaw));
    if (!$queryReport->execute($parametersReport)) {
        log_error('INSERT INTO report failed.');
        return;
    }
    $serial = $dbc->lastInsertId();
    $STATS['reports_inserted']++;

    //insert rptrecord
    foreach ($xmlData['record'] as $record) {
        if (filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $record['ip'] = ip2long($record['ip']);
            $iptype = 'ip';
        } elseif (filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $record['ip'] = inet_pton($record['ip']);
            $iptype = 'ip6';
        } else {
            logMessage('Invalid IP address: ' . $record['ip']);
            continue;
        }
        $queryReportRecord = $dbc->prepare('INSERT INTO rptrecord(serial,' . $iptype . ',rcount,disposition,spf_align,dkim_align,dkimdomain,dkimresult,spfdomain,spfresult,identifier_hfrom)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $parametersReportRecord = NULL;
        $parametersReportRecord[] = $serial;
        $parametersReportRecord[] = $record['ip'];
        $parametersReportRecord[] = $record['count'];
        // some reports contain disposition pass instead of none
        if ($record['disposition'] == 'pass') {
            $record['disposition'] = 'none';
        }
        $parametersReportRecord[] = $record['disposition'];
        // some reports contain different spf_align instead of fail
        if ($record['spf_align'] == 'none' || $record['spf_align'] == 'softfail' || $record['spf_align'] == 'err' || $record['spf_align'] == 'neutral') {
            $record['spf_align'] = 'fail';
        }
        $parametersReportRecord[] = $record['spf_align'];
        $parametersReportRecord[] = $record['dkim_align'];
        $parametersReportRecord[] = $record['dkimDomain'];
        $parametersReportRecord[] = $record['dkimResult'];
        $parametersReportRecord[] = $record['spfDomain'];
        $parametersReportRecord[] = $record['spfResult'];
        $parametersReportRecord[] = $record['hfrom'];
        if (!$queryReportRecord->execute($parametersReportRecord)) {
            log_warn('INSERT INTO rptrecord failed.');
        }
        else { $STATS['records_inserted']++; }
    }
}

function createTableReport() {
    global $dbc;
    $result = $dbc->query('CREATE TABLE `report` (
        `serial` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `mindate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `maxdate` timestamp NULL DEFAULT NULL,
        `domain` varchar(255) NOT NULL,
        `org` varchar(255) NOT NULL,
        `reportid` varchar(255) NOT NULL,
        `email` varchar(255) DEFAULT NULL,
        `extra_contact_info` varchar(255) DEFAULT NULL,
        `policy_adkim` varchar(20) DEFAULT NULL,
        `policy_aspf` varchar(20) DEFAULT NULL,
        `policy_p` varchar(20) DEFAULT NULL,
        `policy_sp` varchar(20) DEFAULT NULL,
        `policy_pct` tinyint(3) unsigned DEFAULT NULL,
        `raw_xml` mediumtext DEFAULT NULL,
        PRIMARY KEY (`serial`),
        UNIQUE KEY `domain` (`domain`,`reportid`),
        KEY `maxdate` (`maxdate`)
        ) AUTO_INCREMENT=1');
    if ($result){
        return true;
    } else {
        return false;
    }
}

function createTableRptrecord() {
    global $dbc;
    $result = $dbc->query("CREATE TABLE `rptrecord` (
        `serial` int(10) unsigned NOT NULL,
        `ip` int(10) unsigned DEFAULT NULL,
        `ip6` binary(16) DEFAULT NULL,
        `rcount` int(10) unsigned NOT NULL,
        `disposition` enum('none','quarantine','reject') DEFAULT NULL,
        `reason` varchar(255) DEFAULT NULL,
        `dkimdomain` varchar(255) DEFAULT NULL,
        `dkimresult` varchar(64) DEFAULT NULL,
        `spfdomain` varchar(255) DEFAULT NULL,
        `spfresult` enum('none','neutral','pass','fail','softfail','temperror','permerror','unknown') DEFAULT NULL,
        `spf_align` enum('fail','pass','unknown') NOT NULL,
        `dkim_align` enum('fail','pass','unknown') NOT NULL,
        `identifier_hfrom` varchar(255) DEFAULT NULL,
        `selector` varchar(128) DEFAULT NULL,
        KEY `serial` (`serial`,`ip`),
        KEY `serial6` (`serial`,`ip6`),
        KEY `hfrom-spf-dkim` (`identifier_hfrom`,`spf_align`,`dkim_align`)
        )");
    if ($result){
        return true;
    } else {
        return false;
    }
}

function log_error($msg) { global $__LOG_LEVEL, $__LOG_LEVELS, $logFile, $logToStdout, $STATS; $STATS['errors']++; log_write('ERROR', $msg, 0); }
function log_warn($msg)  { log_write('WARN',  $msg, 1); }
function log_info($msg)  { log_write('INFO',  $msg, 2); }
function log_debug($msg) { log_write('DEBUG', $msg, 3); }

function logMessage($message) { // backward compat
    log_info($message);
}

function log_write($levelName, $msg, $levelNum) {
    global $__LOG_LEVEL, $logFile, $logToStdout;
    if ($levelNum > $__LOG_LEVEL) return;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $levelName . ' ' . $msg . PHP_EOL;
    if ($logToStdout) {
        echo $line;
    }
    if ($logFile) {
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}

function resetLog($logFilePath) {
    if ($logFilePath) {
        @file_put_contents($logFilePath, '');
    }
}

function emitSummaryAndExit($code) {
    global $STATS;
    $summary = 'done. summary: mode=' . ($STATS['mode'] ?? 'unknown') .
        ' messages=' . $STATS['messages_seen'] .
        ' attachments=' . $STATS['attachments_seen'] .
        ' xml_parsed=' . $STATS['xml_parsed'] .
        ' xml_failed=' . $STATS['xml_failed'] .
        ' reports_inserted=' . $STATS['reports_inserted'] .
        ' reports_skipped=' . $STATS['reports_skipped_existing'] .
        ' records_inserted=' . $STATS['records_inserted'] .
        ' messages_moved=' . $STATS['messages_moved'] .
        ' errors=' . $STATS['errors'];
    log_info($summary);
    exit($code);
}

?>