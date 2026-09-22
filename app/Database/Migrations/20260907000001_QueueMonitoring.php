<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class QueueMonitoring extends Migration
{
    public function up()
    {
        // 1. Add QUEUE_NUMBER to job_queue
        $this->db->query("
            ALTER TABLE job_queue
            ADD COLUMN QUEUE_NUMBER VARCHAR(20) NULL UNIQUE
            AFTER ID_UPT
        ");

        // 2. Create bridge table between legacy log_konsolidasi and CI4 job_queue
        $this->db->query("
            CREATE TABLE IF NOT EXISTS log_konsolidasi_job (
                ID_LOG      INT          NOT NULL,
                JOB_ID      VARCHAR(36)  NOT NULL,
                PRIMARY KEY (ID_LOG),
                INDEX idx_lkj_job_id (JOB_ID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public function down()
    {
        $this->forge->dropTable('log_konsolidasi_job', true);

        if ($this->db->fieldExists('QUEUE_NUMBER', 'job_queue')) {
            $this->forge->dropColumn('job_queue', 'QUEUE_NUMBER');
        }
    }
}
