<?php
/**
 * Cron provider.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 */

declare ( strict_types = 1 );

namespace SatisPress\Provider;

use Cedaro\WP\Plugin\AbstractHookProvider;
use SatisPress\ReleaseManager;
use SatisPress\Repository\MultiRepository;

/**
 * Class to handle cron events.
 */
class Cron extends AbstractHookProvider {
	/**
	 * Release manager.
	 *
	 * @var ReleaseManager
	 */
	protected $release_manager;

	/**
	 * Installed repository.
	 *
	 * @var MultiRepository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @param ReleaseManager  $release_manager Release manager.
	 * @param MultiRepository $repository      Installed repository.
	 */
	public function __construct( ReleaseManager $release_manager, MultiRepository $repository ) {
		$this->release_manager = $release_manager;
		$this->repository      = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'satispress_purge_releases', [ $this, 'purge_releases' ] );
	}

	/**
	 * Purge old releases.
	 */
	public function purge_releases() {
		$options = get_option( 'satispress', [] );
		if ( empty( $options['enable_purge'] ) || 'yes' !== $options['enable_purge'] ) {
			return;
		}

		$packages = $this->repository->all();

		foreach ( $packages as $package ) {
			$releases = $package->get_releases();
			if ( empty( $releases ) ) {
				continue;
			}

			$versions = array_keys( $releases );
			usort( $versions, 'version_compare' );
			$versions = array_reverse( $versions );

			$keep = $this->get_versions_to_keep( $versions );

			foreach ( $releases as $version => $release ) {
				if ( ! in_array( $version, $keep, true ) ) {
					$this->release_manager->delete( $release );
				}
			}
		}
	}

	/**
	 * Get versions to keep based on rules.
	 *
	 * @param array $versions Array of versions, sorted descending.
	 * @return array Array of versions to keep.
	 */
	protected function get_versions_to_keep( array $versions ): array {
		$keep = [];
		if ( empty( $versions ) ) {
			return [];
		}

		// Sort versions descending
		usort( $versions, 'version_compare' );
		$versions = array_reverse( $versions );

		// 1. 3 last versions
		$keep = array_merge( $keep, array_slice( $versions, 0, 3 ) );

		// Parse versions into structured data
		$parsed = [];
		foreach ( $versions as $v ) {
			$parts    = explode( '.', $v );
			$major    = $parts[0] ?? '0';
			$minor    = $parts[1] ?? '0';
			$parsed[] = [
				'version' => $v,
				'major'   => $major,
				'minor'   => $major . '.' . $minor,
			];
		}

		$latest_major = $parsed[0]['major'];
		$latest_minor = $parsed[0]['minor'];

		// 2. 3 last bugfix versions (of the latest minor branch)
		$bugfixes = [];
		foreach ( $parsed as $p ) {
			if ( $p['minor'] === $latest_minor ) {
				$bugfixes[] = $p['version'];
			}
		}
		$keep = array_merge( $keep, array_slice( $bugfixes, 0, 3 ) );

		// 3. 3 last minor versions (latest version of the 3 latest minor branches of the latest major branch)
		$minors      = [];
		$seen_minors = [];
		foreach ( $parsed as $p ) {
			if ( $p['major'] === $latest_major ) {
				if ( ! isset( $seen_minors[ $p['minor'] ] ) ) {
					$seen_minors[ $p['minor'] ] = true;
					$minors[]                   = $p['version'];
				}
			}
		}
		$keep = array_merge( $keep, array_slice( $minors, 0, 3 ) );

		// 4. 3 major versions (latest version of the 3 latest major branches)
		$majors      = [];
		$seen_majors = [];
		foreach ( $parsed as $p ) {
			if ( ! isset( $seen_majors[ $p['major'] ] ) ) {
				$seen_majors[ $p['major'] ] = true;
				$majors[]                   = $p['version'];
			}
		}
		$keep = array_merge( $keep, array_slice( $majors, 0, 3 ) );

		$keep = array_values( array_unique( $keep ) );
		usort( $keep, 'version_compare' );
		return array_reverse( $keep );
	}
}
