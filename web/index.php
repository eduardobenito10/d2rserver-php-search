<?php

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

//use Predis;

$app = new Silex\Application();

$app['debug'] = true;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/views',
));

$app->get('/', function () use ($app) {

    //$q = $_POST['q'];
    $q = isset($_GET['q']) ? $_GET['q'] : '';
    $params['q'] = $q;
    
    $page = isset($_GET['page']) ? $_GET['page'] : 1;
    
    $filtros = array('año:1990-2000');
    $params['filtros'] = $filtros;
    $gruposFacetados = getGruposFacetados();
    $params['gruposFacetados'] = $gruposFacetados;

    // query
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'http://localhost:2020/sparql',
        // You can set any number of default request options.
        'timeout' => 10.0, // 10 segundos
    ]);

    $numResultados = 10;
    $offset = ($page - 1) * $numResultados;
    // Provide the body as a string.

    $prefixs = 'PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>'
            . 'PREFIX owl: <http://www.w3.org/2002/07/owl#>'
            . 'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>'
            . 'PREFIX vocab: <http://localhost:2020/resource/vocab/>'
            . 'PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>'
            . 'PREFIX map: <http://localhost:2020/resource/#>'
            . 'PREFIX db: <http://localhost:2020/resource/>';
    
    $where = ' WHERE {'
            . ' ?s ?p "' . $q . '"'
            . '. ?s rdfs:label ?label' // LABEL
            . '. ?s rdf:type ?type' // TIPO
            //. '.FILTER(?s == "http://localhost:2020/resource/trabajos_academicos/165")'
            . '}';
    
    $countQuery = $prefixs. 'SELECT (count(distinct ?s) as ?c)' . $where;
    
    $response = $client->request('POST', 'http://localhost:2020/sparql', [
        'form_params' => [
            'query' => $countQuery,
            'output' => 'json'
        ]
    ]);

    $body = (string) $response->getBody();
    $json_response = json_decode($body);

    $total = 0;
    if(isset($json_response->results->bindings)){
        $total = $json_response->results->bindings[0]->c->value;
    }
    
    $query = $prefixs. 'SELECT DISTINCT ?s ?label ?type'
            . $where
            . ' LIMIT ' . $numResultados
            . ' OFFSET ' . $offset;

    $params["query"] = $query;
    
    /*
     * SELECT ?trabajo WHERE {
      ?trabajo <http://localhost:2020/resource/vocab/trabajos_academicos_PFC_titulacion> "ITI Electricidad" .
      FILTER(<http://localhost:2020/resource/vocab/trabajos_academicos_ano_finalizacion> > "1999")
      }
      LIMIT 10
      OFFSET 0
     */

    // EJEMPLO 
    /* SELECT DISTINCT ?s WHERE {
      ?s <http://localhost:2020/resource/vocab/actividades_espejo_anoinicio> ?year.
      FILTER((?year > 1984) && (?year < 2010))
      }
      LIMIT 10 OFFSET 0 */

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
    
    // paginación
    $params['page'] = $page;
    $params['filtervariables'] = array();
    $totalPages = ceil($total / $numResultados);
    
    $params['total'] = $total;
    $params['totalPages'] = $totalPages;

    //ficha
    $ficha = null;
    $params['ficha'] = $ficha;

    return $app['twig']->render('index.html.twig', $params);
});

$app->get('/', function (Request $request) {
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'http://localhost:2020/sparql',
        // You can set any number of default request options.
        'timeout' => 2.0,
    ]);

    $redis = new Predis\Client();

// sets message to contian "Hello world"
    $redis->set('message', 'Hello world');

// gets the value of message
    $value = $redis->get('message');

    echo ($redis->exists('message')) ? "Oui" : "please populate the message key";


    // MIN and MAX
    /* SELECT MIN(?o) WHERE {
      ?s vocab:actividades_espejo_anoinicio ?o
      } */

    /* SELECT MAX(?o) WHERE {
      ?s vocab:actividades_espejo_anoinicio ?o
      } */

    //generar intervalos!
    // detalle //

    /*
     * SELECT DISTINCT ?property ?hasValue ?isValueOf
      WHERE {
      { <http://localhost:2020/resource/asignatura/202> ?property ?hasValue }
      UNION
      { ?isValueOf ?property <http://localhost:2020/resource/asignatura/202> }
      }
      ORDER BY (!BOUND(?hasValue)) ?property ?hasValue ?isValueOf
     */

    $body = $response->getBody();

    /*
     * $client = new \GuzzleHttp\Client();
      $res = $client->request('GET', 'https://api.github.com/user', [
      'auth' => ['user', 'pass']
      ]);
      echo $res->getStatusCode();
      // 200
      echo $res->getHeaderLine('content-type');
      // 'application/json; charset=utf8'
      echo $res->getBody();
      // {"type":"User"...'
      // Send an asynchronous request.
      $request = new \GuzzleHttp\Psr7\Request('GET', 'http://httpbin.org');
      $promise = $client->sendAsync($request)->then(function ($response) {
      echo 'I completed! ' . $response->getBody();
      });
      $promise->wait();
     */

    return new Response($body, 200);
});

function getGruposFacetados() {
    // facetas
    $gruposFacetados = array();
    $facetas = array();
    
    $key = 'año:1990-2000';
    $facetas[$key] = array('min' => 1990, 'max' => '2000', 'texto' => '1990-2000');
    $key = 'año:2000-2010';
    $facetas[$key] = array('min' => 2000, 'max' => '2010', 'texto' => '2000-2010');
    $key = 'año:2010-2020';
    $facetas[$key] = array('min' => 2010, 'max' => '2020', 'texto' => '2010-2020');
    $grupo = array('id' => 'año', 'nombre' => 'año', 'tipo' => 'intervalo', 'facetas' => $facetas);
    $gruposFacetados[] = $grupo;

    return $gruposFacetados;
}

$app->run();
