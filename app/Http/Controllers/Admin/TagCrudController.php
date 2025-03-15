<?php

namespace App\Http\Controllers\Admin;

use App\Models\Tag;
use App\Http\Requests\TagRequest;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Exception;

class TagCrudController extends CrudController
{
    use ListOperation;
    use CreateOperation;
    use UpdateOperation;
    use DeleteOperation;
    //   use \Backpack\CRUD\app\Http\Controllers\Operations\InlineCreateOperation;

    /**
     * @throws Exception
     */
    public function setup()
    {
        $this->crud->setModel(Tag::class);
        $this->crud->setRoute(config('backpack.base.route_prefix', 'admin').'/tag');
        $this->crud->setEntityNameStrings('tag', 'tags');
        $this->crud->setFromDb();
    }

    protected function setupCreateOperation()
    {
        $this->crud->setValidation(TagRequest::class);
    }

    protected function setupUpdateOperation()
    {
        $this->crud->setValidation(TagRequest::class);
    }
}
