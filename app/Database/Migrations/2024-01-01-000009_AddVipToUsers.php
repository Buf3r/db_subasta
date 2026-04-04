<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVipToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'vip' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'null'    => false,
                'after'   => 'free_auctions_used',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'vip');
    }
}