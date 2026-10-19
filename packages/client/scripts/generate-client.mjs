#!/usr/bin/env node
/**
 * Generates the typed API client surface from the frozen OpenAPI contract
 * (docs/api/openapi-v1.json):
 *
 *   src/generated/schema.ts     — component schemas as TS types
 *   src/generated/operations.ts — per-operation request/response types
 *
 * Dependency-free on purpose: the contract is OpenAPI 3.1 with a flat,
 * predictable shape ($ref → #/components/schemas), so a small explicit
 * generator is safer than a heavyweight codegen dependency.
 * Regenerate with `npm run generate-client -w @bs/client`; CI fails when the
 * generated files are out of date.
 *
 * Additive-contract rule: endpoints added later appear as new operations
 * after regeneration; nothing hand-written is overwritten (the generator
 * only writes src/generated/*).
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const specPath = join(packageRoot, '..', '..', 'docs', 'api', 'openapi-v1.json');
const outDir = join(packageRoot, 'src', 'generated');

const spec = JSON.parse(readFileSync(specPath, 'utf8'));

// ---------------------------------------------------------------------------
// Naming + type mapping
// ---------------------------------------------------------------------------

function pascal(value) {
  return value
    .replace(/[^a-zA-Z0-9]+(.)/g, (_, chr) => (chr ? chr.toUpperCase() : ''))
    .replace(/^(.)/, (chr) => chr.toUpperCase());
}

function schemaTypeName(ref) {
  // "#/components/schemas/authors_Entry" → "AuthorsEntry"
  return pascal(ref.replace(/^#\//, '').split('/').pop());
}

/** Maps an OpenAPI schema object to a TypeScript type string. */
let qualifyRefs = false;
function toType(schema, indent = '') {
  if (!schema || typeof schema !== 'object') return 'unknown';
  if (schema.$ref) return (qualifyRefs ? 'S.' : '') + schemaTypeName(schema.$ref);

  const inner = indent + '  ';
  if (Array.isArray(schema.enum)) {
    return schema.enum.map((value) => JSON.stringify(value)).join(' | ');
  }
  if (schema.type === 'string') return 'string';
  if (schema.type === 'integer' || schema.type === 'number') return 'number';
  if (schema.type === 'boolean') return 'boolean';
  if (schema.type === 'array') {
    return `Array<${toType(schema.items ?? {}, inner)}>`;
  }
  if (schema.type === 'object' || schema.properties) {
    const required = new Set(schema.required ?? []);
    const lines = Object.entries(schema.properties ?? {}).map(([name, prop]) => {
      const optional = required.has(name) ? '' : '?';
      const description = prop.description
        ? `    /** ${String(prop.description).split('\n')[0]} */\n`
        : '';
      const nullable = prop.nullable === true ? ' | null' : '';
      return `${description}${inner}${JSON.stringify(name)}${optional}: ${toType(prop, inner)}${nullable};`;
    });
    if (lines.length === 0) return 'Record<string, unknown>';
    return `{\n${lines.join('\n')}\n${indent}}`;
  }
  if (Array.isArray(schema.oneOf) || Array.isArray(schema.anyOf)) {
    const variants = schema.oneOf ?? schema.anyOf;
    return variants.map((variant) => toType(variant, indent)).join('\n' + indent + '| ');
  }
  return 'unknown';
}

// ---------------------------------------------------------------------------
// Component schemas
// ---------------------------------------------------------------------------

const schemaEntries = Object.entries(spec.components?.schemas ?? {});

let schemaFile = `/**
 * Generated from docs/api/openapi-v1.json — DO NOT EDIT BY HAND.
 * Regenerate: npm run generate-client -w @bs/client
 *
 * Contract: frozen api-v1 (additive changes only).
 */

`;

for (const [name, schema] of schemaEntries) {
  const typeName = schemaTypeName(`#/components/schemas/${name}`);
  const description = schema.description
    ? `/** ${String(schema.description).split('\n')[0]} */\n`
    : '';
  schemaFile += `${description}export type ${typeName} = ${toType(schema)};\n\n`;
}

// ---------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------

const METHOD_NAMES = { get: 'GET', post: 'POST', put: 'PUT', patch: 'PATCH', delete: 'DELETE' };

function operationId(method, path) {
  // "GET /taxonomies/{handle}/terms" → "getTaxonomiesHandleTerms"
  const pathPart = path
    .split('/')
    .filter(Boolean)
    .map((segment) =>
      segment.startsWith('{') ? 'By' + pascal(segment.slice(1, -1)) : pascal(segment)
    )
    .join('');
  return method.toLowerCase() + pathPart;
}

function parametersType(operation) {
  const query = (operation.parameters ?? []).filter((param) => param.in === 'query');
  const header = (operation.parameters ?? []).filter((param) => param.in === 'header');
  const block = (title, list) => {
    if (list.length === 0) return '';
    const lines = list.map((param) => {
      const optional = param.required ? '' : '?';
      return `    ${JSON.stringify(param.name)}${optional}: ${toType(param.schema ?? {})};`;
    });
    return `  ${title}: {\n${lines.join('\n')}\n  };\n`;
  };
  return (
    '  params: Record<string, string>;\n' +
    block('query', query) +
    block('header', header)
  );
}

function responseBodyType(operation) {
  for (const status of ['200', '201', '202', '204']) {
    const response = operation.responses?.[status];
    if (!response) continue;
    const json = response.content?.['application/json']?.schema;
    if (json) return toType(json);
    if (status === '204') return 'void';
  }
  return 'unknown';
}

function requestBodyType(operation) {
  const body = operation.requestBody;
  if (!body) return undefined;
  const json = body.content?.['application/json']?.schema;
  if (json) return { type: toType(json), kind: 'json' };
  const multipart = body.content?.['multipart/form-data']?.schema;
  if (multipart) return { type: toType(multipart), kind: 'multipart' };
  const octet = body.content?.['application/octet-stream']?.schema;
  if (octet) return { type: toType(octet), kind: 'binary' };
  return undefined;
}

qualifyRefs = true;
let opsFile = `/**
 * Generated operation types from docs/api/openapi-v1.json — DO NOT EDIT BY HAND.
 * Regenerate: npm run generate-client -w @bs/client
 *
 * Each key is "<METHOD> <pathTemplate>". Values describe the typed request
 * and success response. Use with ApiClient.request<...>(). Schema types are
 * namespaced under S (imported from ./schema).
 */

import type * as S from './schema';

`;

const opEntries = [];
for (const [path, pathItem] of Object.entries(spec.paths)) {
  for (const [method, operation] of Object.entries(pathItem)) {
    const upper = METHOD_NAMES[method];
    if (!upper) continue;
    const id = operationId(method, path);
    const body = requestBodyType(operation);
    const response = responseBodyType(operation);
    const summary = operation.summary
      ? `/** ${String(operation.summary).split('\n')[0]} */\n`
      : '';

    opsFile += `${summary}export interface Op_${id} {\n`;
    opsFile += `  method: '${upper}';\n`;
    opsFile += `  path: ${JSON.stringify(path)};\n`;
    opsFile += parametersType(operation);
    if (body) {
      opsFile += `  body: ${body.type};\n`;
      opsFile += `  bodyKind: '${body.kind}';\n`;
    }
    opsFile += `  response: ${response};\n`;
    opsFile += `}\n\n`;
    opEntries.push(`  ${JSON.stringify(`${upper} ${path}`)}: Op_${id};`);
  }
}

opsFile += `export interface Operations {\n${opEntries.join('\n')}\n}\n`;

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'schema.ts'), schemaFile);
writeFileSync(join(outDir, 'operations.ts'), opsFile);

console.log(
  `Generated ${schemaEntries.length} schemas and ${opEntries.length} operations into ${relative(
    process.cwd(),
    outDir
  )}`
);
