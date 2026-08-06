<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GeoIp2\Database\Reader;
use App\Events\ThreatDetected;

class SyslogDaemon extends Command
{
    protected $signature = 'syslog:listen';
    protected $description = 'Listen UDP 514 for SonicWall threat logs';

    public function handle()
    {
        if (!function_exists('socket_create')) {
            $this->error("Ekstensi 'sockets' belum aktif di php.ini!");
            return Command::FAILURE;
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket, '0.0.0.0', 514);

        $this->info("Syslog Server listening on UDP port 514...");

        $geoipPath = storage_path('app/geoip/GeoLite2-City.mmdb');
        if (!file_exists($geoipPath)) {
            $this->error("Database GeoIP tidak ditemukan di: {$geoipPath}");
            return Command::FAILURE;
        }

        $reader = new Reader($geoipPath);

        while (true) {
            socket_recvfrom($socket, $buf, 2048, 0, $from, $port);
            
            if (preg_match('/src=([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/', $buf, $matches)) {
                $sourceIp = $matches[1];

                try {
                    $record = $reader->city($sourceIp);
                    
                    $data = [
                        'ip' => $sourceIp,
                        'country' => $record->country->name ?? 'Unknown',
                        'city' => $record->city->name ?? 'Unknown',
                        'srcLat' => $record->location->latitude,
                        'srcLng' => $record->location->longitude,
                        'dstLat' => -7.4213, 
                        'dstLng' => 109.2422,
                        'time' => now()->format('H:i:s')
                    ];

                    event(new ThreatDetected($data));
                    $this->info("Threat caught from IP: {$sourceIp} ({$data['country']})");

                } catch (\Exception $e) {
                    // Abaikan IP Private / Lokal yang tidak ada di database GeoIP
                }
            }
        }
    }
}