<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Cargo;

use CargoTableSchema;
use CargoUtils;
use Config;
use ExtensionRegistry;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Decides which Cargo (table, field) pairs a reader is allowed to target,
 * and reads the currently stored value for a page.
 *
 * Two independent gates, both of which a target must pass:
 *
 *  1. The wiki's allow-list ($wgSaintapediaSuggestTables). Without an entry
 *     nothing is suggestable — the default is an empty map, so installing
 *     the extension does not expose any data until an admin opts a table in.
 *  2. The live Cargo schema. A table must be in CargoUtils::getTables() and
 *     a field must exist in that table's CargoTableSchema. This is what
 *     keeps a hand-edited LocalSettings entry from reaching the database
 *     layer as an arbitrary identifier, and it also drops fields that were
 *     removed from a template since the allow-list was written.
 *
 * Underscore-prefixed Cargo internals (_pageID, _pageName, _ID, …) are never
 * suggestable: a reader correcting "_pageID" is meaningless, and exposing the
 * internal columns is exactly the "dump every internal column" outcome the
 * allow-list exists to prevent. They stay excluded even under '*'.
 */
class CargoFieldRegistry {

	/** Cargo's own bookkeeping columns, never offered to readers. */
	public const RESERVED_PREFIX = '_';

	private Config $config;
	private ILoadBalancer $loadBalancer;

	/** @var array<string,CargoTableSchema>|null Lazily loaded, per-request */
	private ?array $schemaCache = null;

	/** @var string[]|null Lazily loaded list of real Cargo tables */
	private ?array $tableCache = null;

	public function __construct( Config $config, ILoadBalancer $loadBalancer ) {
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * Whether Cargo is actually available. Every public method degrades to
	 * "nothing is suggestable" when it is not, so a wiki that disables Cargo
	 * mid-life shows no widget rather than fatalling on every page view.
	 */
	public function isCargoAvailable(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'Cargo' )
			&& class_exists( CargoUtils::class );
	}

	/**
	 * Normalize the raw $wgSaintapediaSuggestTables value.
	 *
	 * Accepts:
	 *   [ 'Parishes' => [ 'Address', 'Phone' ] ]   explicit fields
	 *   [ 'Parishes' => '*' ]                       every non-internal field
	 *   [ 'Parishes' => true ]                      same as '*'
	 *   [ 'Parishes' ]                              list form, same as '*'
	 *
	 * Returns table => true (meaning "all fields") or table => string[].
	 * Pure and service-free so it can be unit-tested directly.
	 *
	 * @param mixed $raw
	 * @return array<string,true|string[]>
	 */
	public static function normalizeAllowList( $raw ): array {
		if ( !is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $key => $value ) {
			// List form: [ 'Parishes', 'Dioceses' ] => all fields of each
			if ( is_int( $key ) ) {
				if ( is_string( $value ) && trim( $value ) !== '' ) {
					$out[trim( $value )] = true;
				}
				continue;
			}

			$table = trim( (string)$key );
			if ( $table === '' ) {
				continue;
			}

			if ( $value === '*' || $value === true ) {
				$out[$table] = true;
				continue;
			}

			if ( is_string( $value ) ) {
				$value = [ $value ];
			}
			if ( !is_array( $value ) ) {
				continue;
			}

			$fields = [];
			foreach ( $value as $field ) {
				if ( !is_string( $field ) ) {
					continue;
				}
				$field = trim( $field );
				// A '*' anywhere in the list widens the whole table.
				if ( $field === '*' ) {
					$fields = true;
					break;
				}
				if ( $field === '' || self::isReservedField( $field ) ) {
					continue;
				}
				if ( !in_array( $field, $fields, true ) ) {
					$fields[] = $field;
				}
			}

			if ( $fields === true ) {
				$out[$table] = true;
			} elseif ( $fields ) {
				$out[$table] = $fields;
			}
		}
		return $out;
	}

	/**
	 * Cargo internals such as _pageID / _pageName. Pure; unit-testable.
	 */
	public static function isReservedField( string $field ): bool {
		return $field === '' || $field[0] === self::RESERVED_PREFIX;
	}

	/**
	 * The configured allow-list, normalized.
	 *
	 * @return array<string,true|string[]>
	 */
	public function getAllowList(): array {
		return self::normalizeAllowList( $this->config->get( 'SaintapediaSuggestTables' ) );
	}

	/**
	 * Real Cargo table names (excluding __NEXT replacement tables, which
	 * CargoUtils::getTables() already filters).
	 *
	 * @return string[]
	 */
	private function getRealTables(): array {
		if ( $this->tableCache !== null ) {
			return $this->tableCache;
		}
		$this->tableCache = [];
		if ( !$this->isCargoAvailable() ) {
			return $this->tableCache;
		}
		try {
			$tables = CargoUtils::getTables();
			$this->tableCache = is_array( $tables ) ? $tables : [];
		} catch ( \Throwable $e ) {
			// Cargo tables not set up yet (fresh install, mid-migration).
			$this->logSoftFailure( 'getRealTables', $e );
		}
		return $this->tableCache;
	}

	/**
	 * Schemas for the allow-listed tables that actually exist.
	 *
	 * @return array<string,CargoTableSchema>
	 */
	private function getSchemas(): array {
		if ( $this->schemaCache !== null ) {
			return $this->schemaCache;
		}
		$this->schemaCache = [];

		$wanted = array_keys( $this->getAllowList() );
		$real = $this->getRealTables();
		$known = array_values( array_intersect( $wanted, $real ) );
		if ( !$known ) {
			return $this->schemaCache;
		}

		try {
			$schemas = CargoUtils::getTableSchemas( $known );
			if ( is_array( $schemas ) ) {
				$this->schemaCache = $schemas;
			}
		} catch ( \Throwable $e ) {
			// getTableSchemas() throws on an unknown table; we already
			// filtered against getTables(), but a concurrent Cargo rebuild
			// can still race us. Degrade to "no suggestable fields".
			$this->logSoftFailure( 'getSchemas', $e );
		}
		return $this->schemaCache;
	}

	/**
	 * Field names in $table that are both allow-listed and present in the
	 * live schema, in schema order.
	 *
	 * @return string[]
	 */
	public function getAllowedFields( string $table ): array {
		$allow = $this->getAllowList();
		if ( !isset( $allow[$table] ) ) {
			return [];
		}
		$schemas = $this->getSchemas();
		if ( !isset( $schemas[$table] ) ) {
			return [];
		}

		$schemaFields = array_keys( $schemas[$table]->mFieldDescriptions );
		$wanted = $allow[$table];

		$out = [];
		foreach ( $schemaFields as $field ) {
			$field = (string)$field;
			if ( self::isReservedField( $field ) ) {
				continue;
			}
			if ( $wanted !== true && !in_array( $field, $wanted, true ) ) {
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Whether this exact (table, field) pair may be targeted. The API calls
	 * this before trusting anything from the request.
	 */
	public function isAllowed( string $table, string $field ): bool {
		if ( self::isReservedField( $field ) ) {
			return false;
		}
		return in_array( $field, $this->getAllowedFields( $table ), true );
	}

	/**
	 * Allow-listed tables that hold a row for this page.
	 *
	 * Uses Cargo's own cargo_pages index (page_id => table_name) rather than
	 * probing each table, so a wiki with many allow-listed tables costs one
	 * indexed lookup instead of one query per table.
	 *
	 * @return string[]
	 */
	public function getTablesForPage( int $pageId ): array {
		if ( $pageId <= 0 || !$this->isCargoAvailable() ) {
			return [];
		}
		$allow = $this->getAllowList();
		if ( !$allow ) {
			return [];
		}

		try {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			$res = $dbr->select(
				'cargo_pages',
				[ 'table_name' ],
				[ 'page_id' => $pageId ],
				__METHOD__,
				[ 'DISTINCT' => true ]
			);
		} catch ( \Throwable $e ) {
			// cargo_pages missing => Cargo not installed on this wiki yet.
			$this->logSoftFailure( 'getTablesForPage', $e );
			return [];
		}

		$tables = [];
		foreach ( $res as $row ) {
			$name = (string)$row->table_name;
			if ( isset( $allow[$name] ) && !in_array( $name, $tables, true ) ) {
				$tables[] = $name;
			}
		}
		return $tables;
	}

	/**
	 * Physical column holding this field's value in the Cargo main table.
	 *
	 * Cargo does not always store a field under its own name. For a list
	 * field, or a Coordinates field, the main table gets `Field__full`
	 * (the delimited/derived text) and the individual values live in a
	 * separate helper table; there is no plain `Field` column at all, so
	 * selecting one raises "Unknown column". Everything else is stored
	 * under its own name. Mirrors CargoUtils::createTableFromSchema().
	 *
	 * Pure apart from the description object; unit-testable.
	 *
	 * @param object|null $description CargoFieldDescription, or null when unknown
	 */
	public static function physicalColumn( string $field, $description ): string {
		$isList = (bool)( $description->mIsList ?? false );
		$type = (string)( $description->mType ?? '' );
		if ( $isList || $type === 'Coordinates' ) {
			return $field . '__full';
		}
		return $field;
	}

	/**
	 * Logical field name => physical column, for the given fields of a table.
	 *
	 * @param string[] $fields
	 * @return array<string,string>
	 */
	private function columnMap( string $table, array $fields ): array {
		$descriptions = $this->getSchemas()[$table]->mFieldDescriptions ?? [];
		$map = [];
		foreach ( $fields as $field ) {
			$map[$field] = self::physicalColumn( $field, $descriptions[$field] ?? null );
		}
		return $map;
	}

	/**
	 * Every suggestable (table, field, current value) triple for one page.
	 *
	 * This is what the widget renders as its dropdown and what the API
	 * re-derives to snapshot the current value — the client's copy is never
	 * trusted for either the target or the stored value.
	 *
	 * Capped at $wgSaintapediaSuggestMaxFields so a wide allow-list cannot
	 * inflate every article's HTML.
	 *
	 * @return list<array{table:string,field:string,value:string,type:string,isList:bool}>
	 */
	public function getSuggestableFields( int $pageId ): array {
		$max = (int)$this->config->get( 'SaintapediaSuggestMaxFields' );
		if ( $max < 1 ) {
			return [];
		}

		$out = [];
		foreach ( $this->getTablesForPage( $pageId ) as $table ) {
			$fields = $this->getAllowedFields( $table );
			if ( !$fields ) {
				continue;
			}
			$values = $this->readRow( $table, $fields, $pageId );
			if ( $values === null ) {
				continue;
			}
			$descriptions = $this->getSchemas()[$table]->mFieldDescriptions ?? [];
			foreach ( $fields as $field ) {
				if ( count( $out ) >= $max ) {
					return $out;
				}
				$desc = $descriptions[$field] ?? null;
				$out[] = [
					'table' => $table,
					'field' => $field,
					'value' => $this->clampValue( (string)( $values[$field] ?? '' ) ),
					'type' => $desc && isset( $desc->mType ) ? (string)$desc->mType : '',
					'isList' => (bool)( $desc->mIsList ?? false ),
				];
			}
		}
		return $out;
	}

	/**
	 * Currently stored value for one field, or null when the pair is not
	 * allow-listed or the page has no row in that table.
	 *
	 * The API uses this to snapshot sg_current_value server-side.
	 */
	public function getCurrentValue( int $pageId, string $table, string $field ): ?string {
		if ( !$this->isAllowed( $table, $field ) ) {
			return null;
		}
		$values = $this->readRow( $table, [ $field ], $pageId );
		if ( $values === null || !array_key_exists( $field, $values ) ) {
			return null;
		}
		return $this->clampValue( (string)$values[$field] );
	}

	/**
	 * Read the given fields of this page's row in a Cargo table.
	 *
	 * $table and $fields have already been validated against the live Cargo
	 * schema by the caller, so they are safe to use as identifiers here.
	 * Cargo data may live in a separate database ($wgCargoDBname), so this
	 * goes through CargoUtils::getDB() rather than the wiki's load balancer.
	 *
	 * Fields are selected as `physicalColumn AS logicalField`, so the
	 * returned array is always keyed by the logical Cargo field name even
	 * when the value lives in a `__full` column.
	 *
	 * @param string[] $fields Logical Cargo field names
	 * @return array<string,mixed>|null Null when there is no row / on error
	 */
	private function readRow( string $table, array $fields, int $pageId ): ?array {
		if ( !$fields || $pageId <= 0 || !$this->isCargoAvailable() ) {
			return null;
		}
		// [ logicalName => physicalColumn ] becomes "physicalColumn AS logicalName".
		$select = $this->columnMap( $table, $fields );
		if ( !$select ) {
			return null;
		}
		try {
			$cdb = CargoUtils::getDB();
			$row = $cdb->selectRow(
				$table,
				$select,
				[ '_pageID' => $pageId ],
				__METHOD__
			);
		} catch ( \Throwable $e ) {
			// A table can vanish between the schema read and this query
			// during a Cargo rebuild; treat it as "no data" for this page.
			$this->logSoftFailure( 'readRow:' . $table, $e );
			return null;
		}
		if ( !$row ) {
			return null;
		}
		return (array)$row;
	}

	/**
	 * Keep a stored snapshot to the same length budget as a submitted value,
	 * so a huge Cargo text field cannot bloat the suggestion table.
	 */
	private function clampValue( string $value ): string {
		$max = (int)$this->config->get( 'SaintapediaSuggestMaxValueLength' );
		if ( $max > 0 && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max );
		}
		return $value;
	}

	private function logSoftFailure( string $context, \Throwable $e ): void {
		if ( function_exists( 'wfDebugLog' ) ) {
			wfDebugLog( 'SaintapediaSuggest', "CargoFieldRegistry {$context}: " . $e->getMessage() );
		}
	}
}
