<?php

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

$app = new Silex\Application();

$app['debug'] = true;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app->get('/', function (Request $request) {
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'http://localhost:2020/sparql',
        // You can set any number of default request options.
        'timeout' => 2.0,
    ]);

    // Provide the body as a string.
    $response = $client->request('POST', 'http://localhost:2020/sparql', [
        'form_params' => [
            'query' => 'PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>'
            . 'PREFIX owl: <http://www.w3.org/2002/07/owl#>'
            . 'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>'
            . 'PREFIX vocab: <http://localhost:2020/resource/vocab/>'
            . 'PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>'
            . 'PREFIX map: <http://localhost:2020/resource/#>'
            . 'PREFIX db: <http://localhost:2020/resource/>'
            . 'SELECT DISTINCT * WHERE {  ?s ?p ?o}LIMIT 10',
            'output' => 'xml'
        ]
    ]);

    
    // MIN and MAX
    /*SELECT MIN(?o) WHERE {
    ?s vocab:actividades_espejo_anoinicio ?o
    }*/
    
    /*SELECT MAX(?o) WHERE {
    ?s vocab:actividades_espejo_anoinicio ?o
    }*/
    
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

$app->run();
