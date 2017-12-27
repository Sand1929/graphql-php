<?php
/**
 * Utility for filtering schemas to include only the parts used in queries..
 */
namespace GraphQL\Utils;

use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Language\AST\NodeKind;

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
    # TODO: we're going to assume fields have no arguments
    Visitor::visit($ast, [
      'enter' => function ($node) use ($schema, &$result_stack, &$schema_stack, &$schema_cursor) {
        if (!empty($result_stack) && $node->kind != NodeKind::NAME) {
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
            $schema_cursor = $schema_cursor->getFields();
            $schema_stack[] = $schema_cursor;
            break;
          case NodeKind::FIELD:
            $schema_cursor = $schema_cursor[$node->name->value]->config['type'];
            $result_stack[] = ['key' => $node->name->value, 'value' => ['name' => $schema_cursor->name]];
            $schema_stack[] = $schema_cursor;
            break;
        }
        print($node);
        print(gettype($schema_cursor));
      },
      'leave' => function ($node) use ($schema, &$result_stack, &$schema_stack, &$schema_cursor) {
        if ($node->kind == NodeKind::DOCUMENT || $node->kind == NodeKind::NAME) {
          return;
        }
        $result_last = array_pop($result_stack);
        $schema_last = array_pop($schema_stack);
        switch ($node->kind) {
          case NodeKind::OPERATION_DEFINITION:
            $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = new ObjectType($result_last['value']);
            break;
          case NodeKind::SELECTION_SET:
            $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = $result_last['value'];
            break;
          case NodeKind::FIELD:
            if (empty($result_last['hasChildren'])) {
              $result_last['value'] = $schema_last;
            } else {
              $result_last['value'] = new ObjectType($result_last['value']);
            }
            $result_stack[count($result_stack) - 1]['value'][$result_last['key']] = $result_last['value'];
            break;
        }
      }
    ]);

    print(json_encode($result_stack[0]['value']));
    return new Schema($result_stack[0]['value']);
  }
}
