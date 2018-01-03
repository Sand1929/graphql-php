<?php
/**
 * Utility for filtering schemas to include only the parts used in queries..
 */
namespace GraphQL\Utils;

use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Schema;
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
   * Given a schema and a query string, returns a new schema that includes only
   * those parts used in the query.
   */
  public static function filterSchemaByQuery(Schema $schema, string $query)
  {
    $result_stack = [];
    $schema_stack = [];
    $schema_cursor = $schema;
    $ast = Parser::parse($query, ['noLocation' => true]);
    Visitor::visit($ast, [
      'enter' => function ($node) use ($schema, &$result_stack, &$schema_stack, &$schema_cursor) {
        $actionableNodeKinds = [NodeKind::DOCUMENT, NodeKind::OPERATION_DEFINITION, NodeKind::SELECTION_SET, NodeKind::FIELD, NodeKind::ARGUMENT, NodeKind::INLINE_FRAGMENT];
        if (!empty($result_stack) && in_array($node->kind, $actionableNodeKinds)) {
          $result_stack[count($result_stack) - 1]['hasChildren'] = true;
        }
        print($node);
        print(gettype($schema_cursor));
        switch ($node->kind) {
          case NodeKind::DOCUMENT:
            $result_stack[] = ['key' => null, 'value' => []];
            $schema_cursor = $schema;
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::OPERATION_DEFINITION:
            $result_stack[] = ['key' => $node->operation, 'value' => ['name' => $node->name->value]];
            $schema_cursor = $node->operation == 'query' ? $schema_cursor->getQueryType() : $schema_cursor->getMutationType();
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::SELECTION_SET:
            if (gettype($schema_cursor) == 'object') {
              $result_stack[] = ['key' => 'fields', 'value' => []];
              $schema_cursor = $schema_cursor->getFields();
            } else {
              print("\n\nHI\n");
              print(json_encode($schema_cursor));
              print(get_class($schema_cursor['type']));
              $fieldType = $result_stack[count($result_stack) - 1]['fieldType'];
              print($fieldType);
              if ($fieldType == 'UnionType') {
                $result_stack[] = ['key' => 'types', 'value' => []];
                $schema_cursor = $schema_cursor['type']->getTypes();
                print(json_encode($schema_cursor));
              } else {
                $result_stack[] = ['key' => 'fields', 'value' => []];
                $schema_cursor = $schema_cursor['type']->getFields();
              }
            }
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::FIELD:
            $schema_cursor = $schema_cursor[$node->name->value]->config;
            $fieldTypePath = explode('\\', get_class($schema_cursor['type']));
            $result_stack[] = ['key' => $node->name->value, 'value' => ['type' => ['name' => $schema_cursor['type']->name]], 'fieldType' => $fieldTypePath[count($fieldTypePath) - 1]];
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::ARGUMENT:
            $schema_cursor = $schema_cursor['args'];
            $result_stack[] = ['key' => 'args', 'value' => [$node->name->value => $schema_cursor[$node->name->value]]];
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::INLINE_FRAGMENT:
            foreach ($schema_cursor as $type) {
              if ($type->name == $node->typeCondition->name->value) {
                $schema_cursor = $type;
                break;
              }
            }
            $fieldTypePath = explode('\\', get_class($schema_cursor));
            $result_stack[] = ['key' => null, 'value' => ['type' => ['name' => $node->typeCondition->name->value]], 'fieldType' => $fieldTypePath[count($fieldTypePath) - 1]];
            $schema_stack[] = $schema_cursor;
            break;
        }
      },
      'leave' => function ($node, $key, $parent) use ($schema, &$result_stack, &$schema_stack, &$schema_cursor) {
        $actionableNodeKinds = [NodeKind::OPERATION_DEFINITION, NodeKind::SELECTION_SET, NodeKind::FIELD, NodeKind::ARGUMENT, NodeKind::INLINE_FRAGMENT];
        if (!in_array($node->kind, $actionableNodeKinds)) {
          return;
        }
        $result_last = array_pop($result_stack);
        $schema_last = array_pop($schema_stack);
        $schema_cursor = $schema_stack[count($schema_stack) - 1];
        switch ($node->kind) {
          case NodeKind::OPERATION_DEFINITION:
            $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = new ObjectType($result_last['value']);
            break;
          case NodeKind::SELECTION_SET:
            if ($parent->kind == NodeKind::OPERATION_DEFINITION) {
              $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = $result_last['value'];
            } else {
              $result_stack[count($result_stack) - 1]['value']['type'][$result_last['key']] = $result_last['value'];
            }
            break;
          case NodeKind::FIELD:
            if (empty($result_last['hasChildren'])) {
              $result_last['value']['type'] = $schema_last['type'];
            } else {
              $field_type = self::convertNameToNativeType($result_last['value']['type']['name']);
              if (is_null($field_type)) {
                if ($result_last['fieldType'] == 'UnionType') {
                  $field_type = new UnionType($result_last['value']['type']);
                } else {
                  $field_type = new ObjectType($result_last['value']['type']);
                }
              }
              $result_last['value']['type'] = $field_type;
            }
            $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = $result_last['value'];
            break;
          case NodeKind::ARGUMENT:
            if (array_key_exists($result_last['key'], $result_stack[count($result_stack) - 1]['value'])) {
              $result_stack[count($result_stack) - 1]['value'][$result_last['key']][] = $result_last['value'];
            } else {
              $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = $result_last['value'];
            }
            break;
          case NodeKind::INLINE_FRAGMENT:
            $result_last['value']['type'] = new ObjectType($result_last['value']['type']);
            $result_stack[count($result_stack) - 1]['value'][] = $result_last['value']['type'];
            break;
        }
      }
    ]);

    // Add an empty query if necessary
    if (!array_key_exists('query', $result_stack[0]['value'])) {
      $result_stack[0]['value']['query'] = new ObjectType(['name' => 'Query', 'fields' => []]);
    }

    print("\n\n");
    $test = new Schema($result_stack[0]['value']);
    print(SchemaPrinter::doPrint($test));
    return new Schema($result_stack[0]['value']);

  }
}
