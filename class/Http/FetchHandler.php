<?php
namespace App\Http;

use App\Request\RequestEntity;
use App\Response\ResponseEntity;
use Gt\Http\ArrayBuffer;
use Gt\Fetch\Http;
use Gt\Http\Response;
use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class FetchHandler {
	public function fetchResponse(
		RequestEntity $requestEntity,
		?Http $http = null,
	):ResponseEntity {
		if(!$http) {
			return $this->fetchResponseWithCurl($requestEntity);
		}

		$responseEntity = new ResponseEntity();

		$uri = $this->getFetchUri($requestEntity->getFetchableUri());
		$init = [
			"method" => $requestEntity->getMethod(),
		];
		if($headers = $requestEntity->getFetchableHeaders()) {
			$init["headers"] = $headers;
		}
		if($requestEntity->body) {
			$init["body"] = $requestEntity->getFetchableBody();
		}

		$response = $http->awaitFetch($uri, $init);
		$responseEntity->setStatus($response->status, $response->statusText);

		foreach($response->headers as $header) {
			$responseEntity->addHeader(
				$header->getName(),
				$header->getValuesCommaSeparated(),
			);
		}

		if(str_starts_with(strtolower($response->type), "image/")) {
			$responseEntity->setBody($this->arrayBufferToString(
				$response->awaitArrayBuffer(),
			));
		}
		else {
			$responseEntity->setBody($response->awaitText());
		}

		return $responseEntity;
	}

	private function fetchResponseWithCurl(RequestEntity $requestEntity):ResponseEntity {
		$responseEntity = new ResponseEntity();
		$uri = $this->getFetchUri($requestEntity->getFetchableUri());
		$responseHeaderList = [];
		$responseStatusText = "";
		$body = "";

		$curlHandle = curl_init((string)$uri);
		curl_setopt_array($curlHandle, [
			CURLOPT_CUSTOMREQUEST => $requestEntity->getMethod(),
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HEADER => false,
			CURLOPT_HEADERFUNCTION => function(
				$curlHandle,
				string $rawHeader,
			)use(&$responseHeaderList, &$responseStatusText):int {
				$headerLine = trim($rawHeader);

				if(preg_match("/^HTTP\\/\\S+\\s+(\\d{3})(?:\\s+(.*))?$/", $headerLine, $match)) {
					$responseHeaderList = [];
					$responseStatusText = $match[2] ?? "";
					return strlen($rawHeader);
				}

				if($headerLine !== "" && str_contains($headerLine, ":")) {
					[$key, $value] = explode(":", $headerLine, 2);
					$responseHeaderList []= [trim($key), trim($value)];
				}

				return strlen($rawHeader);
			},
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_USERAGENT => Http::USER_AGENT,
			CURLOPT_WRITEFUNCTION => function($curlHandle, string $content)use(&$body):int {
				$body .= $content;
				return strlen($content);
			},
		]);

		if($headers = $requestEntity->getFetchableHeaders()) {
			$headerLineList = [];
			foreach($headers as $key => $value) {
				if($key === "") {
					continue;
				}
				$headerLineList []= "$key: $value";
			}

			curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headerLineList);
		}

		if(!is_null($requestEntity->body)) {
			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $requestEntity->getFetchableBody());
		}

		if(curl_exec($curlHandle) === false) {
			$error = curl_error($curlHandle);
			curl_close($curlHandle);
			throw new \RuntimeException("Unable to fetch response: $error");
		}

		$status = curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
		curl_close($curlHandle);

		if($responseStatusText === "") {
			$responseStatusText = (new Response($status))->statusText;
		}

		$responseEntity->setStatus($status, $responseStatusText);
		foreach($responseHeaderList as [$key, $value]) {
			$responseEntity->addHeader($key, $value);
		}
		$responseEntity->setBody($body);

		return $responseEntity;
	}

	private function getFetchUri(UriInterface $requestUri):UriInterface {
		$fakeServerUrl = getenv("BEHAT_FAKE_SERVER_URL") ?: null;
		$fakeServerHosts = getenv("BEHAT_FAKE_SERVER_HOSTS") ?: null;
		if(!$fakeServerUrl || !$fakeServerHosts) {
			return $requestUri;
		}

		$hostList = array_map(
			trim(...),
			explode(",", $fakeServerHosts),
		);
		if(!in_array($requestUri->getHost(), $hostList, true)) {
			return $requestUri;
		}

		$fakeServerUri = new Uri($fakeServerUrl);
		return $fakeServerUri
			->withPath($requestUri->getPath())
			->withQuery($requestUri->getQuery());
	}

	private function arrayBufferToString(ArrayBuffer $arrayBuffer):string {
		$body = "";
		foreach($arrayBuffer as $byte) {
			$body .= chr($byte);
		}

		return $body;
	}
}
