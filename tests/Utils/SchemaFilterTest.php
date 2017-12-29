<?php
/**
 * SchemaFilter tests
 */

namespace GraphQL\Tests\Utils;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaFilter;
use GraphQL\Utils\FindBreakingChanges;

class SchemaFilterTest extends \PHPUnit_Framework_TestCase
{
  public function testFiltersOutUnusedTypes()
  {
    $type1 = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => ['type' => Type::string()]
      ]
    ]);
    $type2 = new ObjectType([
      'name' => 'Type2',
      'fields' => [
        'field1' => ['type' => Type::string()]
      ]
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ])
    ]);
    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type1
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type2 }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFiltersOutUnusedNestedTypes()
  {
    $type1 = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => ['type' => Type::string()],
      ]
    ]);
    $type2 = new ObjectType([
      'name' => 'Type2',
      'fields' => [
        'field1' => ['type' => $type1],
        'field2' => ['type' => Type::string()]
      ]
    ]);
    $type3 = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => ['type' => Type::int()],
      ]
    ]);
    $type4 = new ObjectType([
      'name' => 'Type2',
      'fields' => [
        'field1' => ['type' => $type3],
        'field2' => ['type' => Type::string()]
      ]
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type2
        ]
      ])
    ]);
    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type4
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field2 } }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field1 } }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFiltersOutUnusedFieldArguments() {
    $oldType = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => [
          'type' => Type::string(),
          'args' => [
            'name' => Type::string()
          ]
        ]
      ]
    ]);

    $newType = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => [
          'type' => Type::string()
        ]
      ]
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $oldType
        ]
      ])
    ]);

    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $newType
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field1 } }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field1(name: "testing") } }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }
}
