<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddItemCategory extends Migration
{
    public function up()
    {
        $this->forge->addColumn('items', [
            'category' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'tecnologia',
                    'vehiculos', 
                    'hogar',
                    'ropa_accesorios',
                    'alimentos',
                    'herramientas',
                    'arte_coleccion',
                    'deportes',
                    'otros'
                ],
                'null'    => true,
                'default' => 'otros',
                'after'   => 'condition',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('items', 'category');
    }
}