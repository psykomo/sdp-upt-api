<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Queue extends Migration
{
    public function up()
    {
        // Execute raw SQL because CodeIgniter's Forge doesn't fully support all JSON/TIMESTAMP defaults easily in MariaDB 10.1/10.3
        $sql = "
            CREATE TABLE job_queue (
                id VARCHAR(36) NOT NULL,
                type VARCHAR(100) NOT NULL DEFAULT 'generic',
                payload LONGTEXT NOT NULL,
                status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                result TEXT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
                version INT UNSIGNED NOT NULL DEFAULT 0,
                scheduled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_queue_poll (status, scheduled_at, id),
                INDEX idx_queue_type (type, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        $this->db->query($sql);
    }

    public function down()
    {
        $this->forge->dropTable('job_queue', true);
    }
}
