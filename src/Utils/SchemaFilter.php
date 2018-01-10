<?php
/**
 * Utility for filtering schemas to include only the parts used in queries..
 */
namespace GraphQL\Utils;

use Exception;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Utils\SchemaPrinter;

class SchemaFilter
{
  /**
   * Takes a name of a native type and returns the actual native type. If the
   * name is not that of a native type, returns null.
   */
  private static function convertNameToNativeType(string $name)
  {
    switch ($name) {
      case Type::STRING:
        return Type::string();
      case Type::INT:
        return Type::int();
      case TYPE::BOOLEAN:
        return Type::boolean();
      case TYPE::FLOAT:
        return Type::float();
      case TYPE::ID:
        return Type::id();
      default:
        return null;
    }
  }

  /**
   * Checks whether the given operation is present in the syntax tree.
   */
  private static function hasOperation($operation, $ast) {
    foreach ($ast->definitions as $definitionNode) {
      if ($definitionNode->kind == NodeKind::OPERATION_DEFINITION && $definitionNode->operation == $operation) {
        return true;
      }
    }
    return false;
  }

  /**
   * Given a type object, returns the type of the type object as a string.
   */
  private static function getTypeClass($type) {
    if (is_a($type, 'GraphQL\Type\Definition\InterfaceType')) {
      return 'InterfaceType';
    } else if (is_a($type, 'GraphQL\Type\Definition\UnionType')) {
      return 'UnionType';
    } else if (is_a($type, 'GraphQL\Type\Definition\ObjectType')) {
      return 'ObjectType';
    } else if (is_a($type, 'GraphQL\Type\Definition\ScalarType')) {
      return 'ScalarType';
    } else if (is_a($type, 'GraphQL\Type\Definition\EnumType')) {
      return 'EnumType';
    } else {
      throw new Exception('Invalid type class');
    }
  }

  /**
   * Given two field configs, returns a new, merged config.
   */
  private static function mergeFieldData($fieldConfig1, $fieldConfig2) {
    // all we really have to do is merge in arguments
    foreach ($fieldConfig2['args'] as $argName => $argConfig) {
      if (!array_key_exists($argName, $fieldConfig1['args'])) {
        $fieldConfig1['args'][$argName] = $argConfig;
      }
    }
    return $fieldConfig1;
  }

  /**
   * Given two arrays of type data, returns a new array of merged type data.
   */
  private static function mergeTypeData($typeData1, $typeData2) {
    // if we already have an object, we have complete type data already
    if (array_key_exists('object', $typeData1)) {
      return $typeData1;
    }
    if (array_key_exists('object', $typeData2)) {
      return $typeData2;
    }

    // merge fields
    if (array_key_exists('fields', $typeData1['config'])) {
      foreach ($typeData2['config']['fields'] as $fieldName => $fieldConfig) {
        if (!array_key_exists($fieldName, $typeData1['config']['fields'])) {
          $typeData1['config']['fields'][$fieldName] = $fieldConfig;
        } else {
          $typeData1['config']['fields'][$fieldName] = self::mergeFieldData($typeData1['config']['fields'][$fieldName], $fieldConfig);
        }
      }
    }

    // merge interfaces
    if (array_key_exists('interfaces', $typeData1['config'])) {
      $type1InterfaceNames = array_map(function($i) { return $i->name; }, $typeData1['config']['interfaces']);
      foreach ($typeData2['config']['interfaces'] as $interfaceType) {
        if (!in_array($interfaceType->name, $type1InterfaceNames)) {
          $typeData1['config']['interfaces'][] = $interfaceType;
        }
      }
    }

    // merge types for unions
    if (array_key_exists('types', $typeData1['config'])) {
      $type1ObjectTypeNames = array_map(function($o) { return $o->name; }, $typeData1['config']['types']);
      foreach ($typeData2['config']['types'] as $objectType) {
        if (!in_array($objectType->name, $type1ObjectTypeNames)) {
          $typeData1['config']['types'][] = $objectType;
        }
      }
    }

    return $typeData1;
  }

  /**
   * Takes a schema and query tree, and returns data on each Type necessary to do the actual filtering.
   *
   * Basically, we want a version of typemap that is truly flat and includes a little extra metadata.
   */
  private static function getTypeData(Schema $schema, DocumentNode $ast)
  {
    $result = [];
    $schema_stack = [$schema];

    // add query data if necessary
    if (!self::hasOperation('query', $ast)) {
      $query = $schema->getQueryType();
      $result[$query->name] = ['typeClass' => 'ObjectType', 'object' => $query];
    }

    Visitor::visit($ast, [
      'enter' => function ($node) use ($schema, &$result, &$schema_stack) {
        $last = $schema_stack[count($schema_stack) - 1];
        switch ($node->kind) {
          case NodeKind::OPERATION_DEFINITION:
            $last = $node->operation == 'query' ? $last->getQueryType() : $last->getMutationType();
            $schema_stack[] = $last;
            $config = ['name' => $last->name, 'fields' => [], 'interfaces' => []];
            foreach($node->selectionSet->selections as $selectionNode) {
              // Add fields to config
              if ($selectionNode->kind == NodeKind::FIELD) {
                $field = $last->getField($selectionNode->name->value);
                $fieldConfig = ['type' => $field->getType()->name, 'args' => []];
                // Add arguments to this field 
                if (!is_null($selectionNode->arguments)) {
                  foreach($selectionNode->arguments as $argumentNode) {
                    $argument = $field->getArg($argumentNode->name->value);
                    $argumentConfig = ['type' => $argument->getType()->name];
                    if ($argument->defaultValueExists()) {
                      $argumentConfig['defaultValue'] = $argument->defaultValue;
                    }
                    $fieldConfig['args'][$argument->name] = $argumentConfig;
                  }
                }
                $config['fields'][$field->name] = $fieldConfig;
              } else if ($selectionNode->kind == NodeKind::INLINE_FRAGMENT) {
                //TODO
              } else if ($selectionNode->kind == NodeKind::FRAGMENT_SPREAD) {
                //TODO
              }
            }
            // add interfaces
            $fieldNames = array_keys($config['fields']);
            foreach ($last->getInterfaces() as $interfaceType) {
              foreach ($interfaceType->getFields() as $interfaceField) {
                if (in_array($interfaceField->name, $fieldNames)) {
                  $config['interfaces'][] = $interfaceType;
                  break;
                }
              }
            }
            // now we have to add all fields in interfaces that are not already included
            foreach ($config['interfaces'] as $interfaceType) {
              foreach ($interfaceType->getFields() as $interfaceField) {
                if (!in_array($interfaceField->name, $fieldNames)) {
                  $config['fields'][$interfaceField->name] = ['type' => $interfaceField->getType(), 'args' => $interfaceField->args];
                } else {
                  $config['fields'][$interfaceField->name] = self::mergeFieldData($config['fields'][$interfaceField->name], ['type' => $interfaceField->getType(), 'args' => $interfaceField->args]);
                }
              }
            }
            if (array_key_exists($last->name, $result)) {
              $result[$last->name] = self::mergeTypeData($result[$last->name], ['typeClass' => 'ObjectType', 'config' => $config]);
            } else {
              $result[$last->name] = ['typeClass' => 'ObjectType', 'config' => $config];
            }
            break;
          case NodeKind::SELECTION_SET:
            if (method_exists($last, 'getFields')) {
              $last = $last->getFields();
            } else {
              $type = $last->getType();
              $typeClass = self::getTypeClass($type);
              if ($typeClass == 'UnionType') {
                $last = $type->getTypes();
              } else {
                $last = $type->getFields();
              }
            }
            $schema_stack[] = $last;
            break;
          case NodeKind::FIELD:
            $last = $last[$node->name->value];
            $schema_stack[] = $last;
            $type = $last->getType();
            $typeClass = self::getTypeClass($type);
            if ($typeClass == 'EnumType' || $typeClass == 'ScalarType' || is_null($node->selectionSet)) {
              $result[$type->name] = ['typeClass' => $typeClass, 'object' => $type];
              break;
            }

            $config = ['name' => $type->name];
            if ($typeClass == 'ObjectType' || $typeClass == 'InterfaceType') {
              $config['fields'] = [];
              foreach($node->selectionSet->selections as $selectionNode) {
                // Add fields to this config
                if ($selectionNode->kind == NodeKind::FIELD) {
                  $field = $type->getField($selectionNode->name->value);
                  $fieldConfig = ['type' => $field->getType()->name, 'args' => []];
                  // Add arguments to this field
                  if (!is_null($selectionNode->arguments)) {
                    foreach ($selectionNode->arguments as $argumentNode) {
                      $argument = $field->getArg($argumentNode->name->value);
                      $argumentConfig = ['type' => $argument->getType()->name];
                      if ($argument->defaultValueExists()) {
                        $argumentConfig['defaultValue'] = $argument->defaultValue;
                      }
                      $fieldConfig['args'][$argument->name] = $argumentConfig;
                    }
                  }
                  $config['fields'][$field->name] = $fieldConfig;
                } else if ($selectionNode->kind == NodeKind::INLINE_FRAGMENT) {
                  //TODO
                } else if ($selectionNode->kind == NodeKind::FRAGMENT_SPREAD) {
                  //TODO
                }
              }

              // add interfaces
              if ($typeClass == 'ObjectType') {
                $config['interfaces'] = [];
                $fieldNames = array_keys($config['fields']);
                foreach ($type->getInterfaces() as $interfaceType) {
                  foreach ($interfaceType->getFields() as $interfaceField) {
                    if (in_array($interfaceField->name, $fieldNames)) {
                      $config['interfaces'][] = $interfaceType;
                      break;
                    }
                  }
                }
                // now we have to add all fields in interfaces that are not already included
                foreach ($config['interfaces'] as $interfaceType) {
                  foreach ($interfaceType->getFields() as $interfaceField) {
                    if (!in_array($interfaceField->name, $fieldNames)) {
                      $config['fields'][$interfaceField->name] = ['type' => $interfaceField->getType(), 'args' => $interfaceField->args];
                    } else {
                      $config['fields'][$interfaceField->name] = self::mergeFieldData($config['fields'][$interfaceField->name], ['type' => $interfaceField->getType(), 'args' => $interfaceField->args]);
                    }
                  }
                }
              }
            } else if ($typeClass == 'UnionType') {
              $config['types'] = [];
              $possibleTypes = [];
              foreach ($type->getTypes() as $possibleType) {
                $possibleTypes[$possibleType->name] = $possibleType;
              }
              // Add types to union
              foreach($node->selectionSet->selections as $selectionNode) {
                if ($selectionNode->kind == NodeKind::INLINE_FRAGMENT) {
                  $config['types'][] = $possibleTypes[$selectionNode->typeCondition->name->value];
                } else {
                  //TODO
                }
              }
            }
            if (array_key_exists($type->name, $result)) {
              $result[$type->name] = self::mergeTypeData($result[$type->name], ['typeClass' => $typeClass, 'config' => $config]);
            } else {
              $result[$type->name] = ['typeClass' => $typeClass, 'config' => $config];
            }
            break;
          case NodeKind::ARGUMENT:
            $last = $last->getArg($node->name->value);
            $schema_stack[] = $last;
            $type = $last->getType();
            $result[$type->name] = ['type' => 'InputType', 'object' => $type];
            break;
          case NodeKind::INLINE_FRAGMENT:
            //TODO: add types for interfaces
            foreach ($last as $type) {
              if ($type->name == $node->typeCondition->name->value) {
                $last = $type;
                break;
              }
            }
            $schema_stack[] = $last;
            break;
        }
      },
      'leave' => function ($node) use ($schema, &$result, &$schema_stack) {
        $actionableNodeKinds = [NodeKind::OPERATION_DEFINITION, NodeKind::SELECTION_SET, NodeKind::FIELD, NodeKind::ARGUMENT, NodeKind::INLINE_FRAGMENT];
        if (!in_array($node->kind, $actionableNodeKinds)) {
          return;
        }
        $last = array_pop($schema_stack);
      }
    ]);

    print(json_encode($result));
    return $result;
  }

  /**
   * Takes an array of type data and returns an array of the actual types.
   */
  private static function getTypes(Schema $schema, DocumentNode $ast)
  {
    $typeData = self::getTypeData($schema, $ast);
    $result = [];

    $stack = [$schema->getQueryType()->name];
    $justAdded = [$schema->getQueryType()->name];
    $path = [];
    // Add mutation to stack if necessary
    if (self::hasOperation('mutation', $ast)) {
      $stack[] = $schema->getMutationType()->name;
    }

    // TODO: actually use this for circular types
    foreach ($typeData as $key => $value) {
      $result[$key] = null;
    }
    while (!empty($stack)) {
      $type = $stack[count($stack) - 1];
      if (in_array($type, $justAdded)) {
        $path[] = $type;
      }
      $justAdded = [];

      if (array_key_exists('object', $typeData[$type])) {
        $result[$type] = $typeData[$type]['object'];
        array_pop($stack);
        array_pop($path);
        continue;
      }

      if (in_array($type, array_slice($path, 0, -1))) {
        print(json_encode($path));
        print($type);
        throw new Exception('Still working on circular types!');
      }

      // If we've create the objects for all types of the fields, we're ready to turn this config into an object
      $fieldsReady = true;
      if ($typeData[$type]['typeClass'] != 'UnionType') {
        // add types of fields to stack as necessary
        foreach ($typeData[$type]['config']['fields'] as &$fieldConfig) {
          if (gettype($fieldConfig['type']) == 'string') {
            if (array_key_exists('object', $typeData[$fieldConfig['type']])) {
              $result[$fieldConfig['type']] = $typeData[$fieldConfig['type']]['object'];
              $fieldConfig['type'] = $typeData[$fieldConfig['type']]['object'];
            } else {
              $stack[] = $fieldConfig['type'];
              $justAdded[] = $fieldConfig['type'];
              $fieldsReady = false;
            }
          }

          // add types of field args to stack as necessary
          foreach ($fieldConfig['args'] as &$argConfig) {
            if (gettype($argConfig['type']) == 'string') {
              if (array_key_exists('object', $typeData[$argConfig['type']])) {
                $result[$argConfig['type']] = $typeData[$argConfig['type']]['object'];
                $argConfig['type'] = $typeData[$argConfig['type']]['object'];
              } else {
                $stack[] = $argConfig['type'];
                $justAdded[] = $argConfig['type'];
                $fieldsReady = false;
              }
            }
          }
        }
      }
      if ($fieldsReady) {
        if ($typeData[$type]['typeClass'] == 'ObjectType') {
          $typeData[$type]['object'] = new ObjectType($typeData[$type]['config']);
        } else if ($typeData[$type]['typeClass'] == 'InterfaceType') {
          $typeData[$type]['object'] = new InterfaceType($typeData[$type]['config']);
        } else if ($typeData[$type]['typeClass'] == 'UnionType') {
          $typeData[$type]['object'] = new UnionType($typeData[$type]['config']);
        } else {
          throw new Exception('Invalid type class');
        }
        $result[$type] = $typeData[$type]['object'];
      }
    }

    return $result;
  }

  public static function filterSchemaByQuery(Schema $schema, string $query) {
    $ast = Parser::parse($query, ['noLocation' => true]);
    $types = self::getTypes($schema, $ast);
    $config = ['query' => $types[$schema->getQueryType()->name]];
    if (self::hasOperation('mutation', $ast)) {
      $config['mutation'] = $types[$schema->getMutationType()->name];
    }
    //TODO: types
    return new Schema($config);
  }
}
