<?php

use Kirby\Http\Remote;
use Kirby\Cms\Page;

$url = 'https://legal-api.kleiner-als.de/rest';

function getSectionData($url, $section, $force = false)
{
	$apiCache = kirby()->cache('schnti.legal.data');
	$apiData = $apiCache->get($section);

	if ($force === true || $apiData === null) {

		$response = Remote::post("$url/content/$section", [
			'headers' => [
				'Authorization: Basic ' . base64_encode(option('schnti.legal.username') . ':' . option('schnti.legal.password'))
			],
			'data'    => option('schnti.legal.data'),
			'version' => option('schnti.legal.version'),
		]);

		$apiData = $response->content();

		$apiCache->set($section, $apiData); // Cache läuft nicht ab!
	}

	return kirbytext($apiData);
}

function sendMetaData($url, $force = false)
{
	$versionCache = kirby()->cache('schnti.legal.meta');
	$versionData = $versionCache->get('version');
	$version = kirby()->version();

	if ($force === true || $versionData !== $version) {
		try {

			$system = kirby()->system();

			// accounts, content, curl, sessions, mbstring, media, php
			$data['status'] = $system->status();
			
			// Version, Server, PHP, License, Languages
			// Since 4.3.0
			if (method_exists($system, 'info')) {
				$data['info'] = $system->info();
			} else {
				$data['info']['kirby'] = $version;
				$data['info']['php'] = phpversion();
			}

			// License
			if (class_exists('Kirby\Cms\License')) {
				$license = Kirby\Cms\License::read();
				$data['license'] = [
					'activation' =>  $license->activation(),
					'code' =>  $license->code(),
					'domain' =>  $license->domain(),
					'email' =>  $license->email(),
					'order' =>  $license->order(),
					'date' =>  $license->date()
				];
			}

			// Plugins
			$plugins = $system->plugins();
			foreach ($plugins as $plugin) {
				$data['plugins'][] = $plugin->updateStatus()->toArray();
			}

			Remote::post("$url/meta", [
				'headers' => [
					'Authorization: Basic ' . base64_encode(option('schnti.legal.username') . ':' . option('schnti.legal.password'))
				],
				'data' => $data
			]);


			$versionCache->set('version', $version, 60 * 24 * 7); // 60 Minuten * 24 Stunden * 7 Tage
		} catch (Exception $e) {
		}
	}
}

Kirby::plugin('schnti/legal', [
	'options' => [
		'username'   => false,
		'password'   => false,
		'data'       => [],
		'version'    => 'newest',
		'cache.data' => true,
		'cache.meta' => true
	],
	'tags'    => [
		'verantwortlicheStelle' => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->verantwortlicheStelle()->kirbytext();
			}
		],
		'datenschutzbeauftragter' => [
			'html' => function ($tag) {
				$page = $tag->parent();
				return $page->datenschutzbeauftragter()->kirbytext();
			}
		],
		'legal' => [
			'attr' => [
				'class'
			],
			'html' => function ($tag) use ($url) {
				return getSectionData($url, $tag->value);
			}
		],
	],
	'hooks' => [
		'page.render:after' => function (string $contentType, array $data, string $html, Page $page) use ($url) {

			if (!option('debug') && $page->isHomePage()) {
				sendMetaData($url);
			}
		}
	],
	'api' => [
		'routes' => [
			[
				'pattern' => 'legal-refresh',
				'auth' => false,
				'action'  => function () use ($url) {
					getSectionData($url, 'datenschutz', true); // force = true
					getSectionData($url, 'disclaimer', true); // force = true
					return 'Legal Refresh Done';
				}
			],
			[
				'pattern' => 'meta-refresh',
				'auth' => false,
				'action'  => function () use ($url) {
					sendMetaData($url, true); // force = true
					return 'Meta Data Refresh Done';
				}
			]
		]
	]
]);
