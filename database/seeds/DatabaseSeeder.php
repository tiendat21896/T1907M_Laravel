<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
         $this->call(UserSeeder::class);
        factory(\App\Brand::class, 20) ->create();
        factory(\App\Category::class, 20) ->create();
        factory(\App\Product::class, 100) ->create();
    }
//    public function run()
//    {
//        // $this->call(UserSeeder::class);
//        factory(\App\Category::class, 20) ->create();
//    }
//    public function run()
//    {
//        // $this->call(UserSeeder::class);
//        factory(\App\Product::class, 100) ->create();
//    }
}
