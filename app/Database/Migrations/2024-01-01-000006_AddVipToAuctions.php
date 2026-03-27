<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVipToAuctions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('auctions', [
            'vip_start' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'date_completed',
            ],
            'vip_end' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'vip_start',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('auctions', ['vip_start', 'vip_end']);
    }
}