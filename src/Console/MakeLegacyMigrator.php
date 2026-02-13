<?php

namespace Mreycode\LegacyMigrator\Console;

use Illuminate\Console\GeneratorCommand;

class MakeLegacyMigrator extends GeneratorCommand
{
    protected $name = 'make:legacy-migrator';
    protected $description = 'Create a new legacy migrator class';
    protected $type = 'LegacyMigrator';

    // Where to put generated classes
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\LegacyMigrators';
    }

    // Stub file path
    protected function getStub()
    {
        return __DIR__.'/stubs/legacy-migrator.stub';
    }
}