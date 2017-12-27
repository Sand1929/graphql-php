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
  public function testShouldFilterOutUnusedBreakingChanges()
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
        'field1' => ['type' => Type::string()],
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
    $this->assertEquals([], FindBreakingChanges::findRemovedTypes($filteredSchema, $newSchema));
  }
}
