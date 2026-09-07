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
                ID VARCHAR(36) NOT NULL,
                ID_UPT VARBINARY(35) NULL,
                TYPE VARCHAR(100) NOT NULL DEFAULT 'generic',
                PAYLOAD LONGTEXT NOT NULL,
                PRIORITY TINYINT UNSIGNED NOT NULL DEFAULT 0,
                STATUS ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                RESULT TEXT NULL,
                ATTEMPTS TINYINT UNSIGNED NOT NULL DEFAULT 0,
                MAX_ATTEMPTS TINYINT UNSIGNED NOT NULL DEFAULT 3,
                VERSION INT UNSIGNED NOT NULL DEFAULT 0,
                SCHEDULED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                LOCKED_AT TIMESTAMP NULL,
                COMPLETED_AT TIMESTAMP NULL,
                CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (ID),
                INDEX idx_queue_poll (STATUS, PRIORITY, SCHEDULED_AT, ID),
                INDEX idx_queue_type (TYPE, STATUS),
                INDEX idx_queue_upt (ID_UPT),
                CONSTRAINT fk_job_queue_upt FOREIGN KEY (ID_UPT) REFERENCES upt (ID_UPT) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        $this->db->query($sql);
    }

    public function down()
    {
        $this->forge->dropTable('job_queue', true);
    }
}
