<?php

namespace ScoutEngines\Postgres\Test;

use Mockery;
use Laravel\Scout\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use ScoutEngines\Postgres\PostgresEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class PostgresEngineTest extends TestCase
{
    public function test_it_can_be_instantiated()
    {
        list($engine) = $this->getEngine();

        $this->assertInstanceOf(PostgresEngine::class, $engine);
    }

    public function test_update_adds_object_to_index()
    {
        list($engine, $db) = $this->getEngine();

        $db->shouldReceive('query')
            ->andReturn($query = Mockery::mock('stdClass'));
        $query->shouldReceive('selectRaw')
            ->with(
                'to_tsvector(COALESCE(?, get_current_ts_config()), ?) || setweight(to_tsvector(COALESCE(?, get_current_ts_config()), ?), ?) AS tsvector',
                [null, 'Foo', null, '', 'B']
            )
            ->andReturnSelf();
        $query->shouldReceive('value')
            ->with('tsvector')
            ->andReturn('foo');

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('where')
            ->with('table.id', '=', 1)
            ->andReturnSelf();

        $table->shouldReceive('update')
            ->with(['searchable' => 'foo']);

        $engine->update(Collection::make([new TestModel()]));
    }

    public function test_update_do_nothing_if_index_maintenance_turned_off_globally()
    {
        list($engine) = $this->getEngine(['maintain_index' => false]);

        $engine->update(Collection::make([new TestModel()]));
    }

    public function test_delete_removes_object_from_index()
    {
        list($engine, $db) = $this->getEngine();

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('whereIn')
            ->with('table.id', [1])
            ->andReturnSelf();
        $table->shouldReceive('update')
            ->with(['searchable' => null]);

        $engine->delete(Collection::make([new TestModel()]));
    }

    public function test_delete_do_nothing_if_index_maintenance_turned_off_globally()
    {
        list($engine, $db) = $this->getEngine(['maintain_index' => false]);

        $db->shouldReceive('table')
            ->andReturn($table = Mockery::mock('stdClass'));
        $table->shouldReceive('whereIn')
            ->with('table.id', [1])
            ->andReturnSelf();
        $table->shouldReceive('update')
            ->with(['searchable' => null]);

        $engine->delete(Collection::make([new TestModel()]));
    }

    public function test_search()
    {
        list($engine, $db) = $this->getEngine();

        $skip = 0;
        $limit = 5;
        $table = $this->setDbExpectations($db);

        $table->shouldReceive('skip')->with($skip)->andReturnSelf()
            ->shouldReceive('limit')->with($limit)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1)->andReturnSelf()
            ->shouldReceive('where')->with('baz', 'qux');

        $builder = new Builder(new TestModel(), 'foo');
        $builder->where('bar', 1)
            ->where('baz', 'qux')
            ->take(5);

        $engine->search($builder);
    }

    public function test_search_with_order_by()
    {
        list($engine, $db) = $this->getEngine();

        $table = $this->setDbExpectations($db, false);

        $table->shouldReceive('orderBy')->with('bar', 'desc')->andReturnSelf()
            ->shouldReceive('orderBy')->with('baz', 'asc')->andReturnSelf();

        $builder = new Builder(new TestModel(), 'foo');
        $builder->orderBy('bar', 'desc')
            ->orderBy('baz', 'asc');

        $engine->search($builder);
    }

    public function test_search_with_global_config()
    {
        list($engine, $db) = $this->getEngine(['config' => 'simple']);

        $skip = 0;
        $limit = 5;
        $table = $this->setDbExpectations($db);

        $table->shouldReceive('skip')->with($skip)->andReturnSelf()
            ->shouldReceive('limit')->with($limit)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1);

        $builder = new Builder(new TestModel(), 'foo');
        $builder->where('bar', 1)->take(5);

        $engine->search($builder);
    }

    public function test_search_with_model_config()
    {
        list($engine, $db) = $this->getEngine(['config' => 'simple']);

        $skip = 0;
        $limit = 5;
        $table = $this->setDbExpectations($db);

        $table->shouldReceive('skip')->with($skip)->andReturnSelf()
            ->shouldReceive('limit')->with($limit)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1);

        $model = new TestModel();
        $model->searchableOptions['config'] = 'english';

        $builder = new Builder($model, 'foo');
        $builder->where('bar', 1)->take(5);

        $engine->search($builder);
    }

    public function test_search_with_soft_deletes()
    {
        list($engine, $db) = $this->getEngine();

        $table = $this->setDbExpectations($db);

        $table->shouldReceive('skip')->with(0)->andReturnSelf()
            ->shouldReceive('limit')->with(5)->andReturnSelf()
            ->shouldReceive('where')->with('bar', 1)->andReturnSelf()
            ->shouldReceive('whereNull')->with('table.deleted_at');

        $builder = new Builder(new SoftDeletableTestModel(), 'foo');
        $builder->where('bar', 1)->take(5);

        $engine->search($builder);
    }

    public function test_maps_results_to_models()
    {
        list($engine) = $this->getEngine();

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('table.id');
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('whereIn')->once()->with('table.id', [1])->andReturn($model);
        $model->shouldReceive('get')->once()->andReturn(Collection::make([new TestModel()]));

        $results = $engine->map(
            new Builder(new TestModel, 'foo'),
            json_decode('[{"id": 1, "tsrank": 0.33, "total_count": 1}]'),
            $model
        );

        $this->assertCount(1, $results);
    }

    public function test_map_filters_out_no_longer_existing_models()
    {
        list($engine) = $this->getEngine();

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('table.id');
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('whereIn')->once()->with('table.id', [1, 2])->andReturn($model);

        $expectedModel = new SoftDeletableTestModel();
        $expectedModel->id = 2;

        $model->shouldReceive('get')->once()->andReturn(Collection::make([$expectedModel]));

        $models = $engine->map(
            new Builder(new TestModel, 'foo'),
            json_decode('[{"id": 1, "tsrank": 0.33, "total_count": 2}, {"id": 2, "tsrank": 0.31, "total_count": 2}]'),
            $model
        );

        $this->assertCount(1, $models);
        $this->assertEquals(2, $models->first()->id);
    }

    public function test_it_returns_total_count()
    {
        list($engine) = $this->getEngine();

        $count = $engine->getTotalCount(
            json_decode('[{"id": 1, "tsrank": 0.33, "total_count": 100}]')
        );

        $this->assertEquals(100, $count);
    }

    public function test_map_ids_returns_right_key()
    {
        list($engine, $db) = $this->getEngine();

        $table = $this->setDbExpectations($db);
        $table->shouldReceive('getBindings')->andReturn([null, 'foo']);
        $builder = new Builder(new TestModel, 'foo');
        $results = $engine->search($builder);
        $ids = $engine->mapIds($results);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $ids);
        $this->assertEquals([1, 2], $ids->all());
    }

    protected function getEngine($config = [])
    {
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->andReturn($db = Mockery::mock(Connection::class));

        $db->shouldReceive('getDriverName')->andReturn('pgsql');

        return [new PostgresEngine($resolver, $config), $db];
    }

    protected function setDbExpectations($db, $withDefaultOrderBy = true)
    {
        $db->shouldReceive('table')
            ->andReturn($table = TestModel::query());
        $db->shouldReceive('raw')
            ->with('plainto_tsquery(COALESCE(?, get_current_ts_config()), ?) AS "tsquery"')
            ->andReturn('plainto_tsquery(COALESCE(?, get_current_ts_config()), ?) AS "tsquery"');

        Model::unguard();
        $table->shouldReceive('crossJoin')
                ->with('plainto_tsquery(COALESCE(?, get_current_ts_config()), ?) AS "tsquery"')
                ->andReturnSelf()
            ->shouldReceive('addBinding')
                ->with(Mockery::type('array'), 'join')
                ->andReturnSelf()
            ->shouldReceive('select')
                ->with('table.id')
                ->andReturnSelf()
            ->shouldReceive('selectRaw')
                ->with('ts_rank(searchable,"tsquery") AS rank')
                ->andReturnSelf()
            ->shouldReceive('selectRaw')
                ->with('COUNT(*) OVER () AS total_count')
                ->andReturnSelf()
            ->shouldReceive('whereRaw')
                ->andReturnSelf()
            ->shouldReceive('get')
                ->andReturn(Collection::make([
                    new TestModel(['id' => 1]),
                    new TestModel(['id' => 2]),
                ]));
        Model::reguard();

        if ($withDefaultOrderBy) {
            $table->shouldReceive('orderBy')
                    ->with('rank', 'desc')
                    ->andReturnSelf()
                ->shouldReceive('orderBy')
                    ->with('table.id')
                    ->andReturnSelf();
        }


        return $table;
    }
}


$eloquentBuilder = Mockery::mock(EloquentBuilder::class)->makePartial();

class TestModel extends Model
{
    public $searchableOptions = [
        'rank' => [
            'fields' => [
                'nullable' => 'B',
            ],
        ],
    ];

    public $searchableArray = [
        'text' => 'Foo',
        'nullable' => null,
    ];

    public $searchableAdditionalArray = [];

    public function searchableAs()
    {
        return 'searchable';
    }

    public function getQualifiedKeyName()
    {
        return 'table.id';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getIdAttribute($value)
    {
        return $value ?: 1;
    }

    public function getTable()
    {
        return 'table';
    }

    public function toSearchableArray()
    {
        return $this->searchableArray;
    }

    public function searchableOptions()
    {
        return $this->searchableOptions;
    }

    public function searchableAdditionalArray()
    {
        return $this->searchableAdditionalArray;
    }

    /**
     * Begin querying the model.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query()
    {
        global $eloquentBuilder;
        return $eloquentBuilder;
    }
}

class SoftDeletableTestModel extends TestModel
{
    use SoftDeletes;
}
