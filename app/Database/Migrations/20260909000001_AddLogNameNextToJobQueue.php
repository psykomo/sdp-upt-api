<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLogNameNextToJobQueue extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('log_konsolidasi_job')) {
            if (!$this->db->fieldExists('LOG_NAME_NEXT', 'log_konsolidasi_job')) {
                $this->db->query("
                    ALTER TABLE log_konsolidasi_job
                    ADD COLUMN LOG_NAME_NEXT VARCHAR(50) NULL AFTER JOB_ID
                ");
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('log_konsolidasi_job')) {
            if ($this->db->fieldExists('LOG_NAME_NEXT', 'log_konsolidasi_job')) {
                $this->forge->dropColumn('log_konsolidasi_job', 'LOG_NAME_NEXT');
            }
        }
    }
}
