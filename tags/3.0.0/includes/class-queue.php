<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Queue — Arka Plan İşleme Kuyruğu
 *
 * Toplu dönüşüm ve silme işlemlerini WP-Cron üzerinden yürütür.
 * Tarayıcı sekmesi kapatılsa dahi işlem devam eder.
 *
 * Kuyruk anatomisi (wp_options):
 *   erdo_queue_ids      — bekleyen ID dizisi
 *   erdo_queue_action   — 'convert' | 'delete'
 *   erdo_queue_total    — toplam ID sayısı (başlangıçta)
 *   erdo_queue_done     — işlenen ID sayısı
 *   erdo_queue_errors   — hatalı ID'ler dizisi
 *   erdo_queue_status   — 'idle' | 'running' | 'done' | 'cancelled'
 *   erdo_queue_started  — Unix timestamp
 */
class ERDO_Queue {

    const HOOK         = 'erdo_bg_process';
    const INTERVAL     = 'erdo_two_min';
    const BATCH_SIZE   = 25;

    /* ------------------------------------------------------------------
       CRON INTERVAL KAYDI
    ------------------------------------------------------------------ */

    public static function register_interval() {
        add_filter( 'cron_schedules', function ( $schedules ) {
            $schedules[ self::INTERVAL ] = array(
                'interval' => 120,
                'display'  => 'Every 2 minutes (Erdo)',
            );
            return $schedules;
        } );
    }

    /* ------------------------------------------------------------------
       BAŞLAT
    ------------------------------------------------------------------ */

    public static function start( array $ids, string $action = 'convert' ) {
        if ( empty( $ids ) ) {
            return false;
        }

        self::cancel(); // Önceki kuyruğu temizle

        update_option( 'erdo_queue_ids',     $ids,       false );
        update_option( 'erdo_queue_action',  $action,    false );
        update_option( 'erdo_queue_total',   count( $ids ), false );
        update_option( 'erdo_queue_done',    0,          false );
        update_option( 'erdo_queue_errors',  array(),    false );
        update_option( 'erdo_queue_skipped', 0,          false );
        update_option( 'erdo_queue_status',  'running',  false );
        update_option( 'erdo_queue_started', time(),     false );
        // "Geri al" için: yalnızca dönüştürme kuyruğunun kimlikleri saklanır.
        update_option( 'erdo_queue_undo_ids', 'convert' === $action ? array_map( 'intval', $ids ) : array(), false );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), self::INTERVAL, self::HOOK );
        }

        // İlk batch'i hemen işle
        self::process_batch();

        return true;
    }

    /* ------------------------------------------------------------------
       İPTAL
    ------------------------------------------------------------------ */

    public static function cancel() {
        wp_clear_scheduled_hook( self::HOOK );
        update_option( 'erdo_queue_status', 'cancelled' );
        delete_option( 'erdo_queue_ids' );
    }

    /* ------------------------------------------------------------------
       BATCH İŞLE (Cron hook'u tarafından tetiklenir)
    ------------------------------------------------------------------ */

    /**
     * Bir parti işler. Cron ve (pencere açıkken) tarayıcı aynı anda çağırabilir;
     * kilit aynı görsellerin iki kez işlenmesini önler. Takılı kalan kilit 5 dk sonra düşer.
     */
    public static function process_batch( $size = self::BATCH_SIZE ) {
        if ( ! add_option( 'erdo_queue_lock', time(), '', false ) ) {
            if ( time() - (int) get_option( 'erdo_queue_lock', 0 ) < 300 ) {
                return;
            }
            update_option( 'erdo_queue_lock', time(), false );
        }
        try {
            // Cron, argümansız do_action ile boş dize geçirir: varsayılana düş.
            self::run_batch( (int) $size > 0 ? (int) $size : self::BATCH_SIZE );
        } finally {
            delete_option( 'erdo_queue_lock' );
        }
    }

    private static function run_batch( $size ) {
        if ( get_option( 'erdo_queue_status' ) !== 'running' ) {
            wp_clear_scheduled_hook( self::HOOK );
            return;
        }

        $ids     = get_option( 'erdo_queue_ids', array() );
        $action  = get_option( 'erdo_queue_action', 'convert' );
        $errors  = get_option( 'erdo_queue_errors', array() );
        $done    = (int) get_option( 'erdo_queue_done', 0 );
        $skipped = (int) get_option( 'erdo_queue_skipped', 0 );

        if ( empty( $ids ) ) {
            self::finish();
            return;
        }

        $batch = array_splice( $ids, 0, $size );

        foreach ( $batch as $id ) {
            $id = (int) $id;

            if ( 'convert' === $action ) {
                $result = ERDO_Converter::convert( $id );
                // Zaten modern formatta / animasyonlu / kopyası daha büyük çıkan
                // görseller hata değil, atlanan (skip) sayılır.
                if ( is_wp_error( $result ) ) {
                    $errors[] = $id;
                } elseif ( 'ok' !== $result['status'] ) {
                    $skipped++;
                }
            } elseif ( 'delete' === $action ) {
                // Toplu "silme" = sıkı denetimli karantina; kullanımda olabilecekler atlanır.
                $result = ERDO_Quarantine::quarantine( $id, true );
                if ( is_wp_error( $result ) ) {
                    if ( in_array( $result->get_error_code(), array( 'in_use', 'maybe_in_use', 'already' ), true ) ) {
                        $skipped++;
                    } else {
                        $errors[] = $id;
                    }
                }
            }

            $done++;
        }

        update_option( 'erdo_queue_ids',     $ids,     false );
        update_option( 'erdo_queue_done',    $done,    false );
        update_option( 'erdo_queue_errors',  $errors,  false );
        update_option( 'erdo_queue_skipped', $skipped, false );

        if ( empty( $ids ) ) {
            self::finish();
        }
    }

    /* ------------------------------------------------------------------
       BİTİŞ
    ------------------------------------------------------------------ */

    private static function finish() {
        wp_clear_scheduled_hook( self::HOOK );
        update_option( 'erdo_queue_status', 'done' );
        delete_option( 'erdo_queue_ids' );

        if ( 'convert' === get_option( 'erdo_queue_action' ) ) {
            $made = (int) get_option( 'erdo_queue_done', 0 ) - (int) get_option( 'erdo_queue_skipped', 0 ) - count( (array) get_option( 'erdo_queue_errors', array() ) );
            ERDO_Activity::log( 'convert', $made, array( 'queue' => (int) get_option( 'erdo_queue_started', 0 ) ) );
        }

        // İstatistikleri güncelle
        ERDO_Auditor::clear_all_cache();
    }

    /* ------------------------------------------------------------------
       DURUM
    ------------------------------------------------------------------ */

    public static function get_status() {
        $status  = get_option( 'erdo_queue_status', 'idle' );
        $total   = (int) get_option( 'erdo_queue_total', 0 );
        $done    = (int) get_option( 'erdo_queue_done', 0 );
        $errors  = (array) get_option( 'erdo_queue_errors', array() );
        $skipped = (int) get_option( 'erdo_queue_skipped', 0 );

        $pct = ( $total > 0 ) ? round( ( $done / $total ) * 100 ) : 0;

        return array(
            'status'   => $status,
            'total'    => $total,
            'done'     => $done,
            'errors'   => count( $errors ),
            'skipped'  => $skipped,
            'percent'  => $pct,
            'running'  => ( 'running' === $status ),
            'action'   => get_option( 'erdo_queue_action', 'convert' ),
            'started'  => (int) get_option( 'erdo_queue_started', 0 ),
        );
    }

    /** Son dönüştürme kuyruğu (başlangıç zamanıyla tanınır) hâlâ geri alınabilir mi? */
    public static function can_undo( $started = 0 ) {
        return ! self::is_running()
            && get_option( 'erdo_queue_undo_ids' )
            && ( ! $started || (int) get_option( 'erdo_queue_started', 0 ) === $started );
    }

    /**
     * Son dönüştürme kuyruğunu geri alır: o kuyruktaki görsellerin WebP/AVIF
     * kopyaları silinir (orijinaller zaten hiç değişmedi). Döner: geri alınan sayı.
     */
    public static function undo() {
        if ( ! self::can_undo() ) {
            return 0;
        }
        $ids = array_map( 'intval', (array) get_option( 'erdo_queue_undo_ids', array() ) );
        foreach ( $ids as $id ) {
            ERDO_Converter::revert( $id );
        }
        delete_option( 'erdo_queue_undo_ids' );
        ERDO_Auditor::clear_all_cache();
        return count( $ids );
    }

    public static function is_running() {
        return get_option( 'erdo_queue_status' ) === 'running';
    }
}
