<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Activity — son toplu işlemlerin kısa günlüğü (Genel Bakış > Recent activity).
 *
 * Tek bir seçenekte en fazla MAX kayıt tutulur. Aynı türden işlemler 10 dakika
 * içinde gelirse tek kayıtta birleşir (toplu karantina görsel başına bir AJAX
 * isteğiyle ilerlediği için). Kayıt yalnızca bilgi ve geri alma ipucu taşır;
 * geri almanın kendisi ilgili sınıfta yapılır (Renamer batch, Quarantine restore,
 * Queue undo).
 */
class ERDO_Activity {

    const OPTION = 'erdo_activity';
    const MAX    = 20;
    const MERGE  = 600;

    /**
     * @param string $type  rename | quarantine | restore | purge | convert | ai_accept | alt
     * @param int    $count Etkilenen görsel sayısı.
     * @param array  $extra Ör. array( 'batch' => 'b123' ) — aynı batch aynı kayda yazılır.
     */
    public static function log( $type, $count = 1, array $extra = array() ) {
        $count = (int) $count;
        if ( $count < 1 ) {
            return;
        }
        $log  = self::all();
        $now  = time();
        $last = $log ? $log[0] : null;

        $same = $last && $last['type'] === $type && (
            isset( $extra['batch'] )
                ? ( $last['extra']['batch'] ?? '' ) === $extra['batch']
                : ( $now - (int) $last['time'] ) < self::MERGE && empty( $last['extra']['batch'] )
        );

        if ( $same ) {
            $log[0]['count'] += $count;
            $log[0]['time']   = $now;
            $log[0]['extra']  = array_merge( $last['extra'], $extra );
        } else {
            array_unshift( $log, array(
                'type'  => $type,
                'count' => $count,
                'time'  => $now,
                'extra' => $extra,
            ) );
        }
        update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
    }

    public static function all() {
        $log = get_option( self::OPTION, array() );
        return is_array( $log ) ? array_values( $log ) : array();
    }

    /** Arayüz için: okunabilir metin + (varsa) geri alma bilgisi. */
    public static function for_display( $limit = 5 ) {
        $out = array();
        foreach ( array_slice( self::all(), 0, $limit ) as $e ) {
            $n    = (int) $e['count'];
            $undo = null;
            switch ( $e['type'] ) {
                case 'rename':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Renamed %d image', 'Renamed %d images', $n, 'erdo-image-optimizer' ), $n );
                    if ( ! empty( $e['extra']['batch'] ) && ERDO_Renamer::batch_exists( $e['extra']['batch'] ) ) {
                        $undo = array( 'kind' => 'rename', 'batch' => $e['extra']['batch'] );
                    }
                    break;
                case 'quarantine':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Moved %d image to quarantine', 'Moved %d images to quarantine', $n, 'erdo-image-optimizer' ), $n );
                    $undo = array( 'kind' => 'quarantine' );
                    break;
                case 'restore':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Restored %d image from quarantine', 'Restored %d images from quarantine', $n, 'erdo-image-optimizer' ), $n );
                    break;
                case 'purge':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Deleted %d image permanently', 'Deleted %d images permanently', $n, 'erdo-image-optimizer' ), $n );
                    break;
                case 'convert':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Created WebP/AVIF copies for %d image', 'Created WebP/AVIF copies for %d images', $n, 'erdo-image-optimizer' ), $n );
                    if ( ! empty( $e['extra']['queue'] ) && ERDO_Queue::can_undo( (int) $e['extra']['queue'] ) ) {
                        $undo = array( 'kind' => 'convert' );
                    }
                    break;
                case 'ai_accept':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Accepted AI suggestions for %d image', 'Accepted AI suggestions for %d images', $n, 'erdo-image-optimizer' ), $n );
                    break;
                case 'alt':
                    /* translators: %d: number of images */
                    $text = sprintf( _n( 'Added alt text from the file name to %d image', 'Added alt text from the file name to %d images', $n, 'erdo-image-optimizer' ), $n );
                    break;
                default:
                    continue 2;
            }
            $out[] = array(
                'text' => $text,
                /* translators: %s: human-readable time difference, e.g. "2 hours" */
                'when' => sprintf( __( '%s ago', 'erdo-image-optimizer' ), human_time_diff( (int) $e['time'] ) ),
                'undo' => $undo,
            );
        }
        return $out;
    }
}
