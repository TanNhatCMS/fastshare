<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::create(['name' => 'admin']);

        $this->createFoler(1, null);

        $user = User::factory()->create([ // mật khẩu ở UserFactory mặc định là "1234"
            'name' => 'Admin',
            'username' => 'admin',
        ]);

        $user->assignRole('admin'); // giữ lại từ đây khi deploy lên production

        // xóa từ đây đến hết phương thức khi deploy lên production
        $this->createFoler(10);

        $user = User::factory()->create([
            'username' => 'user1'
        ]);

        $user->assignRole("Folder.2.admin");

        $user = User::factory()->create([
            'username' => 'user2'
        ]);

        $user->assignRole("Folder.3.editor");

        $user = User::factory()->create([
            'username' => 'user3'
        ]);

        $user->assignRole("Folder.4.viewer");
    }

    private function createFoler($num = 1, $folder_id = 1): void
    {
        Folder::factory($num)->create([
            'folder_id' =>  $folder_id === null ? null : $folder_id,
            'permission' => 'create,read,update,delete',
        ])->each(function ($folder) {
            (new Folder)->initAuthoredFolder($folder->id);
        });
    }
}
