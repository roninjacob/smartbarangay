"""Synthetic arithmetic checks, not hardware benchmark results."""
import unittest
from analyze_qr_benchmark import summarize


class BenchmarkAnalysisTest(unittest.TestCase):
    def test_known_synthetic_samples_and_trials(self):
        result = summarize('''[BOOT] Standalone
[QRBENCH] session=1 attempt=1 completed_ms=1000 total_us=200000 ready_us=150000 decoded=1 captured=1
[QRBENCH] session=1 attempt=2 completed_ms=1500 total_us=300000 ready_us=250000 decoded=1 captured=1
[QRBENCH] session=1 attempt=3 completed_ms=2000 total_us=400000 ready_us=0 decoded=0 captured=0
[TRIAL] code=A success=1 latency_ms=600
[TRIAL] code=B success=0
[TRIAL] code=C success=1 latency_ms=1000''')
        self.assertEqual(result['average_processing_ms'], 300)
        self.assertEqual(result['completed_scans_per_second'], 2)
        self.assertEqual(result['successful_scan_to_result_ms']['median'], 200)
        self.assertEqual(result['successful_scan_to_result_ms']['p95_nearest_rank'], 250)
        self.assertEqual(result['presentation_trials']['success_rate'], 2 / 3)
        self.assertEqual(result['failed_frame_attempts'], 1)

    def test_no_code_is_not_invented_latency(self):
        result = summarize('[QRBENCH] session=1 attempt=1 completed_ms=1 total_us=200000 ready_us=0 decoded=0')
        self.assertIsNone(result['successful_scan_to_result_ms']['median'])
        self.assertIsNone(result['completed_scans_per_second'])
        with self.assertRaises(ValueError):
            summarize('[QRTIME] samples=1 total_ms=200')


if __name__ == '__main__':
    unittest.main()
