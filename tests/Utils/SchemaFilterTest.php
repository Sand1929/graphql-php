<?php
/**
 * SchemaFilter tests
 */

namespace GraphQL\Tests\Utils;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
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
          'type1' => ['type' => $oldType, 'args' => ['name1' => Type::string()]]
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

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1(name1: "test") { field1(name: "testing") } }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }
 
  public function testFiltersOutUnusedTypesInMutations()
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
        'name' => 'Query',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ]),
      'mutation' => new ObjectType([
        'name' => 'Mutation',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ])
    ]);
    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'Query',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ]),
      'mutation' => new ObjectType([
        'name' => 'Mutation',
        'fields' => [
          'type1' => $type1
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'mutation Mutation { type1 }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'mutation Mutation { type2 }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }
 
  public function testFiltersOutUnusedMutations()
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
        'name' => 'Query',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ]),
      'mutation' => new ObjectType([
        'name' => 'Mutation',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ])
    ]);
    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'Query',
        'fields' => [
          'type1' => $type1,
          'type2' => $type2
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query Query { type1 }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'mutation Mutation { type2 }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFilterWorksWithInterfaces() {
    $interface1 = new InterfaceType([
      'name' => 'Interface1',
      'fields' => [
        'field1' => Type::string()
      ],
      'resolveType' => function () {
      }
    ]);
    $oldType = new ObjectType([
      'name' => 'Type1',
      'interfaces' => [$interface1],
      'fields' => [
        'field1' => Type::string()
      ]
    ]);
    $newType = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => Type::string()
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
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFilterWorksWithUnions() {
    $typeInUnion1 = new ObjectType([
      'name' => 'TypeInUnion1',
      'fields' => [
        'field1' => Type::string()
      ]
    ]);

    $typeInUnion2 = new ObjectType([
      'name' => 'TypeInUnion2',
      'fields' => [
        'field1' => Type::string()
      ]
    ]);

    $unionTypeThatLosesATypeOld = new UnionType([
      'name' => 'UnionTypeThatLosesAType',
      'types' => [$typeInUnion1, $typeInUnion2],
      'resolveType' => function () {
      }
    ]);

    $unionTypeThatLosesATypeNew = new UnionType([
      'name' => 'UnionTypeThatLosesAType',
      'types' => [$typeInUnion1],
      'resolveType' => function () {
      }
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $unionTypeThatLosesATypeOld
        ]
      ])
    ]);

    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $unionTypeThatLosesATypeNew
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { ... on TypeInUnion1 { field1 } } }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { ... on TypeInUnion2 { field1 } } }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }
 
  public function testFilterWorksWithEnums() {
    $enumTypeThatLosesAValueOld = new EnumType([
      'name' => 'EnumTypeThatLosesAValue',
      'values' => [
        'VALUE0' => 0,
        'VALUE1' => 1,
        'VALUE2' => 2
      ]
    ]);

    $enumTypeThatLosesAValueNew = new EnumType([
      'name' => 'EnumTypeThatLosesAValue',
      'values' => [
        'VALUE1' => 1,
        'VALUE2' => 2
      ]
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $enumTypeThatLosesAValueOld
        ]
      ])
    ]);

    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $enumTypeThatLosesAValueNew
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFilterWorksForMultipleTypeOccurrences() {
    $type1 = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => ['type' => Type::string()],
        'field2' => ['type' => Type::string()]
      ]
    ]);
    $type2 = new ObjectType([
      'name' => 'Type1',
      'fields' => [
        'field1' => ['type' => Type::string()]
      ]
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type1,
          'type11' => $type1
        ]
      ])
    ]);
    $newSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'type1' => $type2,
          'type11' => $type2
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field1 } }');
    $this->assertEquals([], FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema));

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { type1 { field1 } type11 { field2 } }');
    $this->assertGreaterThan(0, count(FindBreakingChanges::findBreakingChanges($filteredSchema, $newSchema)));
  }

  public function testFilterWorksForCircularTypes() {
    $userType = null;
    $userType = new ObjectType([
      'name' => 'User',
      'fields' => function() use (&$userType) {
        return [
          'email' => [
            'type' => Type::string()
          ],
          'posts' => [
            'type' => Type::listOf($postType)
          ]
        ];
      }
    ]);

    $postType = new ObjectType([
      'name' => 'Post',
      'fields' => function () use (&$userType) {
        return [
          'usersWhoLike' => [
            'type' => Type::listOf($userType)
          ]
        ];
      }
    ]);

    $oldSchema = new Schema([
      'query' => new ObjectType([
        'name' => 'root',
        'fields' => [
          'user' => $userType,
        ]
      ])
    ]);

    $filteredSchema = SchemaFilter::filterSchemaByQuery($oldSchema, 'query root { user } }');
  }
}
