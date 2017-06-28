<?php

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

//use Predis;

$app = new Silex\Application();
$app->register(new Silex\Provider\RoutingServiceProvider());

$app['debug'] = true;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/views',
));

$app['prefixes'] = 'PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>'
        . 'PREFIX owl: <http://www.w3.org/2002/07/owl#>'
        . 'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>'
        . 'PREFIX vocab: <http://localhost:2020/resource/vocab/>'
        . 'PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>'
        . 'PREFIX map: <http://localhost:2020/resource/#>'
        . 'PREFIX db: <http://localhost:2020/resource/>';

$app->get('/', function () use ($app) {
    $params = array();

    // query
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'http://localhost:2020/sparql',
        // You can set any number of default request options.
        'timeout' => 30.0, // 30 segundos
    ]);

    $listaConsultas = array(
        'investigador_actividades' => 'Actividades por investigador',
        'director_trabajos' => 'Trabajos académicos por director',
        'especialidad_trabajos' => 'Trabajos académicos por especialidad',
        'anio_trabajos' => 'Trabajos académicos por año',
        'anio_actividades' => 'Actividades por año'
    );

    $params['listaConsultas'] = $listaConsultas;

    $q = isset($_GET['q']) ? $_GET['q'] : '';
    $params['q'] = $q;

    if ($q) {
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $type = isset($_GET['type']) ? $_GET['type'] : '';
        $params['type'] = $type;

        $filtros = isset($_GET['filtros']) ? $_GET['filtros'] : array();
        $params['filtros'] = $filtros;

        $numResultados = 10;
        $offset = ($page - 1) * $numResultados;

        $innerWhere = ' ?s ?p "' . $q . '"'
                . '. ?s rdfs:label ?label' // LABEL
                . '. ?s rdf:type ?type';

        foreach ($filtros as $property => $filtro) {
            $filterVariable = '?' . $property;
            $innerWhere .= '. ?s vocab:' . $property . ' ' . $filterVariable;
        }

        $filters = getFiltros($type, $filtros);

        $where = ' WHERE {'
                . $innerWhere
                . $filters
                . '}';

        // TYPES
        $redis = new Predis\Client();
        $types = getTypes($redis, $client, $app['prefixes'], $q, $where);
        $params['listTypes'] = $types;

        // Obtenemos los grupos facetados dependiendo del tipo de contenido seleccionado.
        $gruposFacetados = getGruposFacetados($client, $type, $app['prefixes'], $innerWhere, $filtros);
        $params['gruposFacetados'] = $gruposFacetados;

        // TOTAL
        $total = getTotal($client, $app['prefixes'], $where);
        $params['total'] = $total;

        // QUERY
        $query = $app['prefixes'] . 'SELECT DISTINCT ?s ?label ?type'
                . $where
                . ' LIMIT ' . $numResultados
                . ' OFFSET ' . $offset;

        $params['query'] = $query;

        $response = $client->request('POST', 'http://localhost:2020/sparql', [
            'form_params' => [
                'query' => $query,
                'output' => 'json'
            ]
        ]);

        $body = (string) $response->getBody();
        $json_response = json_decode($body);

        $resultados = array_map(function($binding) {
            return array(
                "label" => $binding->label->value,
                "url" => $binding->s->value,
                "type" => $binding->type->value);
        }, $json_response->results->bindings);

        $params['resultados'] = $resultados;

        // PAGINACIÓN
        $params['page'] = $page;
        $params['filtervariables'] = $filtros;
        $totalPages = ceil($total / $numResultados);
        $params['totalPages'] = $totalPages;

        // FICHA - Cuando el resultado es único, mostramos una ficha detallada de ese recurso
        if ($total == 1) {
            $detalle = getDetalle($client, $app['prefixes'], $resultados[0]['url']);
            $params['detalle'] = $detalle;
        }
    } else {
        $c = isset($_GET['c']) ? $_GET['c'] : '';

        if ($c) {
            switch ($c) {
                case 'investigador_actividades':
                    $query = $app['prefixes']
                            . 'SELECT ?name (count(distinct ?a) as ?count)'
                            . ' WHERE {'
                            . ' ?s rdf:type vocab:persona.'
                            . ' ?s rdfs:label ?name.'
                            . ' ?a vocab:Actividades_investigador ?name'
                            . ' }'
                            . 'group by ?name order by DESC(?count)';
                    break;
                case 'director_trabajos':
                    $query = $app['prefixes']
                            . 'SELECT ?name (count(distinct ?a) as ?count)'
                            . ' WHERE {'
                            . ' ?s rdf:type vocab:persona.'
                            . ' ?s rdfs:label ?name.'
                            . ' ?a vocab:trabajos_academicos_director ?name'
                            . ' }'
                            . 'group by ?name order by DESC(?count)';
                    break;
                case 'especialidad_trabajos':
                    $query = $app['prefixes']
                            . 'SELECT ?name (count(distinct ?a) as ?count)'
                            . ' WHERE {'
                            . ' ?s rdf:type vocab:especialidades.'
                            . ' ?s rdfs:label ?name.'
                            . ' ?a vocab:trabajos_academicos_especialidad ?name'
                            . ' }'
                            . 'group by ?name order by DESC(?count)';
                    break;
                case 'anio_trabajos':
                    $query = $app['prefixes']
                            . 'SELECT ?name (count(distinct ?a) as ?count)'
                            . ' WHERE {'
                            . ' ?a vocab:trabajos_academicos_ano_finalizacion ?name'
                            . ' }'
                            . 'group by ?name order by DESC(?count)';
                    break;
                case 'anio_actividades':
                    $query = $app['prefixes']
                            . 'SELECT ?name (count(distinct ?a) as ?count)'
                            . ' WHERE {'
                            . ' ?a vocab:actividades_anoinicio ?name'
                            . ' }'
                            . 'group by ?name order by DESC(?count)';
                    break;
            }
            $consulta['titulo'] = $listaConsultas[$c];
            $consulta['rows'] = doConsulta($client, $query);
            $params['consulta'] = $consulta;
        }
    }

    return $app['twig']->render('index.html.twig', $params);
});

// Obtiene la cadena de filtros, en caso de pasarle un valor a $property
// no se incluirán las condiciones de esa propiedad
function getFiltros($type, $filtros, $pProperty = null) {
    $filters = "";
    if ($type || $filtros) {
        $filters = '. FILTER(';
        if ($type) {
            $filters .= '?type = vocab:' . $type;
        }

        foreach ($filtros as $property => $filtro) {
            if ($property != $pProperty) {
                $filterVariable = '?' . $property;
                //$innerWhere .= '. ?s vocab:' . $property . ' ' . $filterVariable;

                $filtroPropiedad = "";
                foreach ($filtro as $valor) {
                    $filtroPropiedad .= ($filtroPropiedad == "") ? ' && ( ' : '||';
                    // Si la variable no es numérica es necesario que vaya entre comillas dobles
                    $filtroPropiedad .= $filterVariable . ' = "' . $valor . '"';
                    if (is_numeric($valor)) {
                        $filtroPropiedad .= '|| ' . $filterVariable . ' = ' . $valor;
                    }
                }
                $filtroPropiedad .= ')';
                $filters .= $filtroPropiedad;
            }
        }

        $filters .= ')';
    }
    return $filters;
}

function getDetalle($client, $prefixes, $id) {

    $queryDetalle = $prefixes
            . ' SELECT DISTINCT ?property ?hasValue ?isValueOf'
            . ' WHERE {'
            . '{ <' . $id . '> ?property ?hasValue }'
            . ' UNION'
            . ' {'
            . ' ?isValueOf ?property <' . $id . '> }'
            . ' }'
            . 'ORDER BY (!BOUND(?hasValue)) ?property ?hasValue ?isValueOf';

    $response = $client->request('POST', 'http://localhost:2020/sparql', [
        'form_params' => [
            'query' => $queryDetalle,
            'output' => 'json'
        ]
    ]);

    $body = (string) $response->getBody();
    $json_response = json_decode($body);

    $detalle = array_map(function($binding) {
        return array(
            "property" => $binding->property->value,
            "hasValue" => $binding->hasValue->value);
    }, $json_response->results->bindings);

    return $detalle;
}

function getTotal($client, $prefixes, $where) {
    $queryCount = $prefixes . 'SELECT (count(distinct ?s) as ?c)' . $where;

    $response = $client->request('POST', 'http://localhost:2020/sparql', [
        'form_params' => [
            'query' => $queryCount,
            'output' => 'json'
        ]
    ]);

    $body = (string) $response->getBody();
    $json_response = json_decode($body);

    $total = 0;
    if (isset($json_response->results->bindings)) {
        $total = $json_response->results->bindings[0]->c->value;
    }

    return $total;
}

function getTypes($redis, $client, $prefixes, $q, $where) {
    $key = 'query:' . $q;
    if ($redis->exists($key)) {
        // Recupero de redis si existe el índice
        $types = json_decode($redis->get($key));
    } else {
        $queryTypes = $prefixes . 'SELECT ?type (count(distinct ?s) as ?count)' . $where . 'group by ?type';
        $response = $client->request('POST', 'http://localhost:2020/sparql', [
            'form_params' => [
                'query' => $queryTypes,
                'output' => 'json'
            ]
        ]);

        $body = (string) $response->getBody();
        $json_response = json_decode($body);

        $types = array_map(function($binding) {
            return array(
                "type" => $binding->type->value,
                "count" => $binding->count->value);
        }, $json_response->results->bindings);

        // Guardo en redis
        $redis->set($key, json_encode($types));
    }
    return $types;
}

function getGruposFacetados($client, $type, $prefixes, $innerWhere, $filtros) {
    $gruposFacetados = array();
    $facetas = array();

    switch ($type) {
        case 'actividad':
            $propiedades[] = array('titulo' => 'AÑO', 'field' => 'actividades_anoinicio', 'desc' => true);
            $propiedades[] = array('titulo' => 'INVESTIGADOR', 'field' => 'Actividades_investigador');
            break;
        case 'trabajos_academicos':
            $propiedades[] = array('titulo' => 'AÑO', 'field' => 'trabajos_academicos_ano_finalizacion', 'desc' => true);
            $propiedades[] = array('titulo' => 'TITULACION', 'field' => 'trabajos_academicos_PFC_titulacion', 'desc' => true);
            $propiedades[] = array('titulo' => 'TIPO TRABAJO', 'field' => 'trabajos_academicos_TipoTrabajo', 'desc' => true);
            break;
        default:
            $propiedades = array();
    }

    foreach ($propiedades as $propiedad) {
        $filters = getFiltros($type, $filtros, $propiedad['field']);

        $where = ' WHERE {'
                . '?s vocab:' . $propiedad['field'] . ' ?value .'
                . $innerWhere
                . $filters
                . '}';

        $order = (isset($propiedad['desc'])) ? 'DESC(?value)' : '?value';
        $queryFaceta = $prefixes . 'SELECT ?value (count(distinct ?s) as ?c)' . $where . 'group by ?value order by ' . $order;

        $response = $client->request('POST', 'http://localhost:2020/sparql', [
            'form_params' => [
                'query' => $queryFaceta,
                'output' => 'json'
            ]
        ]);

        $body = (string) $response->getBody();
        $json_response = json_decode($body);

        $facetas = array_map(function($binding) {
            $value = $binding->value->value;
            return array(
                "key" => $value,
                "value" => $value,
                "count" => $binding->c->value);
        }, $json_response->results->bindings);

        // Ordenamos las facetas según el número de resultados
        /* usort($facetas, function($a, $b) {
          return ($a['count'] > $b['count']) ? -1 : 1;
          }); */

        $grupo = array('id' => $propiedad['field'], 'titulo' => $propiedad['titulo'], 'tipo' => '', 'facetas' => $facetas);
        $gruposFacetados[] = $grupo;
    }
    return $gruposFacetados;
}

function doConsulta($client, $query) {
    $response = $client->request('POST', 'http://localhost:2020/sparql', [
        'form_params' => [
            'query' => $query,
            'output' => 'json'
        ]
    ]);

    $body = (string) $response->getBody();
    $json_response = json_decode($body);

    $rows = array_map(function($binding) {
        return array(
            "name" => $binding->name->value,
            "count" => $binding->count->value);
    }, $json_response->results->bindings);

    return $rows;
}

$app->run();
