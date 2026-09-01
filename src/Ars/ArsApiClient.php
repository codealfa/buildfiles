<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\Ars;

use JsonException;
use RuntimeException;

/**
 * A minimal client for the Akeeba Release System JSON:API.
 *
 * This is deliberately not a general purpose JSON:API client. It knows just enough to read and write the three
 * resources the environment synchronisation cares about — automatic item descriptions, environments, and the
 * categories they belong to — using the same Joomla API Application integration Akeeba Release Maker uses to publish
 * a release.
 *
 * @see  https://github.com/akeeba/ars/blob/development/assets/http/api.http
 */
class ArsApiClient
{
	/**
	 * The number of records to ask for in a single list request.
	 *
	 * The API defaults to 20, which would turn the environment vocabulary — around seventy records — into four round
	 * trips for no reason.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * The base URL of the site, without a trailing slash, e.g. `https://www.example.com`.
	 *
	 * @var  string
	 */
	private readonly string $endpoint;

	/**
	 * Public constructor.
	 *
	 * @param   string       $endpoint  The base URL of the Joomla site running ARS.
	 * @param   string       $token     The Joomla API token identifying the user we act as.
	 * @param   string|null  $caCert    Custom CA bundle to verify the site's certificate against, if any.
	 */
	public function __construct(
		string $endpoint, private readonly string $token, private readonly ?string $caCert = null
	)
	{
		$endpoint = trim($endpoint);

		if ($endpoint === '')
		{
			throw new RuntimeException('The ARS API endpoint is empty. Set `release.api.endpoint`.');
		}

		if (trim($this->token) === '')
		{
			throw new RuntimeException('The ARS API token is empty. Set `release.api.token`.');
		}

		$this->endpoint = rtrim($endpoint, '/');
	}

	/**
	 * Retrieves every record of a resource matching a filter, following pagination.
	 *
	 * @param   string  $resource  The resource name, e.g. `environments`.
	 * @param   array   $query     The query string parameters, e.g. `['category_id' => 1]`.
	 *
	 * @return  array[]  The `attributes` of each record.
	 */
	public function getAll(string $resource, array $query = []): array
	{
		$records = [];
		$offset  = 0;

		while (true)
		{
			$page = $this->request(
				'GET',
				$resource,
				null,
				null,
				$query + ['page[limit]' => self::PAGE_SIZE, 'page[offset]' => $offset]
			);

			$data = $page['data'] ?? [];

			foreach ($data as $record)
			{
				$records[] = $record['attributes'] ?? [];
			}

			/**
			 * A short page is the last page. ARS does report `meta.total-pages`, but that is a page count, not a record
			 * count, and it is not returned by every endpoint; the short page test needs neither.
			 */
			if (count($data) < self::PAGE_SIZE)
			{
				return $records;
			}

			$offset += self::PAGE_SIZE;
		}
	}

	/**
	 * Creates a new record.
	 *
	 * @param   string  $resource  The resource name, e.g. `environments`.
	 * @param   array   $payload   The column => value pairs to create the record with.
	 *
	 * @return  array  The `attributes` of the newly created record.
	 */
	public function create(string $resource, array $payload): array
	{
		$response = $this->request('POST', $resource, null, $payload);

		return $response['data']['attributes'] ?? [];
	}

	/**
	 * Updates an existing record.
	 *
	 * The API merges: any column left out of the payload keeps its current value.
	 *
	 * @param   string      $resource  The resource name, e.g. `autodescriptions`.
	 * @param   int|string  $id        The ID of the record to update.
	 * @param   array       $payload   The column => value pairs to change.
	 *
	 * @return  array  The `attributes` of the updated record.
	 */
	public function update(string $resource, int|string $id, array $payload): array
	{
		$response = $this->request('PATCH', $resource, $id, $payload);

		return $response['data']['attributes'] ?? [];
	}

	/**
	 * Performs a single API request and decodes the JSON:API document it returns.
	 *
	 * @param   string           $method    The HTTP verb.
	 * @param   string           $resource  The resource name, e.g. `environments`.
	 * @param   int|string|null  $id        The record ID, for the endpoints which address a single record.
	 * @param   array|null       $payload   The request body, JSON encoded, for POST and PATCH.
	 * @param   array            $query     The query string parameters.
	 *
	 * @return  array  The decoded JSON:API document.
	 */
	private function request(
		string $method, string $resource, int|string|null $id = null, ?array $payload = null, array $query = []
	): array
	{
		$url = sprintf('%s/api/index.php/v1/ars/%s', $this->endpoint, $resource)
			. ($id === null ? '' : '/' . rawurlencode((string) $id))
			. (empty($query) ? '' : '?' . http_build_query($query));

		$headers = [
			'Accept: application/vnd.api+json',
			'X-Joomla-Token: ' . $this->token,
			'User-Agent: AkeebaBuildFiles/1.0',
		];

		$curl = curl_init($url);

		if ($curl === false)
		{
			throw new RuntimeException(sprintf('Cannot initialise a cURL request to %s.', $url));
		}

		curl_setopt_array(
			$curl,
			[
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 60,
			]
		);

		if ($payload !== null)
		{
			$headers[] = 'Content-Type: application/json';

			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
		}

		if (!empty($this->caCert))
		{
			curl_setopt($curl, CURLOPT_CAINFO, $this->caCert);
		}

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		$body   = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error  = curl_error($curl);


		if ($body === false)
		{
			throw new RuntimeException(sprintf('Cannot talk to %s: %s', $url, $error));
		}

		if ($status < 200 || $status > 299)
		{
			throw new RuntimeException(
				sprintf('%s %s returned HTTP %d: %s', $method, $url, $status, $this->getErrorDetail((string) $body))
			);
		}

		/**
		 * A 204 No Content, which DELETE returns, has no document to decode. We do not use DELETE, but returning an
		 * empty array is more useful than throwing a JSON error if we ever do.
		 */
		if (trim((string) $body) === '')
		{
			return [];
		}

		try
		{
			$decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e)
		{
			throw new RuntimeException(
				sprintf('%s %s did not return valid JSON: %s', $method, $url, $e->getMessage()), 0, $e
			);
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Extracts something human readable out of a JSON:API error document.
	 *
	 * @param   string  $body  The raw response body.
	 *
	 * @return  string
	 */
	private function getErrorDetail(string $body): string
	{
		$decoded = json_decode($body, true);
		$errors  = $decoded['errors'] ?? null;

		if (!is_array($errors) || empty($errors))
		{
			return substr(trim($body), 0, 500);
		}

		return implode(
			'; ',
			array_map(
				fn(array $error): string => (string) ($error['detail'] ?? $error['title'] ?? 'Unknown error'),
				$errors
			)
		);
	}
}
