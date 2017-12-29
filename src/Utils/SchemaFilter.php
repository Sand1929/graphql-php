<?php
/**
 * Utility for filtering schemas to include only the parts used in queries..
 */
namespace GraphQL\Utils;

use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Utils\SchemaPrinter;

class SchemaFilter
{
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
        $actionableNodeKinds = [NodeKind::DOCUMENT, NodeKind::OPERATION_DEFINITION, NodeKind::SELECTION_SET, NodeKind::FIELD, NodeKind::ARGUMENT];
        if (!empty($result_stack) && in_array($node->kind, $actionableNodeKinds)) {
          $result_stack[count($result_stack) - 1]['hasChildren'] = true;
        }
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
            $result_stack[] = ['key' => 'fields', 'value' => []];
            if (gettype($schema_cursor) == 'object') {
              $schema_cursor = $schema_cursor->getFields();
            } else {
              $schema_cursor = $schema_cursor['type']->getFields();
            }
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::FIELD:
            print("\n\nHI\n\n");
            print(json_encode($schema_cursor[$node->name->value]->config));
            $schema_cursor = $schema_cursor[$node->name->value]->config;
            $result_stack[] = ['key' => $node->name->value, 'value' => ['type' => ['name' => $schema_cursor['type']->name]]];
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::ARGUMENT:
            print("\n\nHI\n\n");
            print(json_encode($schema_cursor));
            $schema_cursor = $schema_cursor['args'];
            $result_stack[] = ['key' => 'args', 'value' => [$node->name->value => $schema_cursor[$node->name->value]]];
            $schema_stack[] = $schema_cursor;
            break;
        }
        print($node);
        print(gettype($schema_cursor));
      },
      'leave' => function ($node, $key, $parent) use ($schema, &$result_stack, &$schema_stack, &$schema_cursor) {
        $actionableNodeKinds = [NodeKind::OPERATION_DEFINITION, NodeKind::SELECTION_SET, NodeKind::FIELD, NodeKind::ARGUMENT];
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
              print("\n\nBYE\n\n");
              print(json_encode($result_stack[count($result_stack) - 1]));
              print(json_encode($result_last['value']));
              //TODO
              if ($result_last['value']['type']['name'] == 'String') {
                $result_last['value']['type'] = Type::string();
              } else {
                $result_last['value']['type'] = new ObjectType($result_last['value']['type']);
              }
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
        }
      }
    ]);

    print("\n\n");
    $test = new Schema($result_stack[0]['value']);
    print(SchemaPrinter::doPrint($test));
    return new Schema($result_stack[0]['value']);

  }
}
