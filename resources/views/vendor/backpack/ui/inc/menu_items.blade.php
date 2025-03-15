{{-- This file is used for menu items by any Backpack v6 theme --}}
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('dashboard') }}"><i class="la la-home nav-icon"></i> {{
        trans('backpack::base.dashboard') }}</a></li>

<x-backpack::menu-item :title="trans('backpack::crud.file_manager')" icon="la la-files-o"
                       :link="backpack_url('elfinder')"/>
<x-backpack::menu-dropdown title="Add-ons" icon="la la-puzzle-piece">
    <x-backpack::menu-dropdown-header title="News" />
    <x-backpack::menu-dropdown-item title="Articles" icon="la la-newspaper-o" :link="backpack_url('article')" />
    <x-backpack::menu-dropdown-item title="Categories" icon="la la-list" :link="backpack_url('category')" />
    <x-backpack::menu-dropdown-item title="Tags" icon="la la-tag" :link="backpack_url('tag')" />
</x-backpack::menu-dropdown>
