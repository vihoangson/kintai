<?php

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DatabaseSeeder
 * @author ntd1712
 */
class DatabaseSeeder extends Seeder
{
    /** {@inheritdoc} */
    public function run()
    {
        config(['params' => require __DIR__ . '/../../modules/config.params.php']);
        $now = date('Y-m-d H:i:s');
        $faker = \Faker\Factory::create();

        Model::unguard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (glob(__DIR__ . '/*') as $v)
        {
            if (false === stripos($v, __CLASS__))
            {
                include_once $v;

                $name = basename($v, '.php');
                $table = strtolower(implode('_', preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/x',
                    str_replace('TableSeeder', '', $name))));
                $seeder = $this->resolve($name);

                if (method_exists($seeder, 'data'))
                {
                    $data = $this->resolve($name)->data($faker);
                    array_walk($data, function(&$value) use($now) {
                        $value += [
                            'added_at' => $now,
                            'added_by' => null,
                            'modified_at' => $now,
                            'modified_by' => null,
                            'is_deleted' => false,
                            'application_key' => config('app.key')
                        ];
                    });

                    DB::table($table)->truncate();
                    DB::table($table)->insert($data);

                    if (isset($this->command))
                    {
                        $this->command->getOutput()->writeln("<info>Seeded:</info> $name");
                    }
                }
                else
                {
                    $this->call($name);
                }
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        Model::reguard();
    }
}