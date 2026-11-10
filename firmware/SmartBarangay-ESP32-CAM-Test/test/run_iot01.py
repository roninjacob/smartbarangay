"""Host-only source regression and actual quirc synthetic-image compatibility.
Requires local gcc/g++, PHP and the existing BaconQrCode Composer autoloader.
No Arduino upload, serial access, Laravel boot or real database operations.
"""
from pathlib import Path
import argparse
import configparser
import hashlib
import json
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def run(args):
    subprocess.run([str(arg) for arg in args], check=True, cwd=ROOT)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--autoload', default=str(ROOT.parents[1]/'vendor/autoload.php'),
                        help='BaconQrCode autoloader; defaults to the enclosing Laravel vendor/autoload.php')
    args = parser.parse_args()
    out = ROOT / '.iot01-test'
    out.mkdir(exist_ok=True)
    source = (ROOT / 'src/main.cpp').read_text(encoding='utf-8')
    baseline = json.loads((ROOT/'test/iot01-baseline.json').read_text(encoding='utf-8'))
    def section(text, begin, end):
        return text[text.index(begin):text.index(end, text.index(begin))]
    # Portable non-secret QR-06 checksum evidence; private recovery is external.
    camera = section(source, 'bool initializeCamera(', 'void setQrMessage(')
    assert hashlib.sha256(camera.encode()).hexdigest() == baseline['camera_init_sha256']
    assert 'constexpr uint32_t QR_SCAN_INTERVAL_MS = 1000;' in source
    assert 'constexpr bool QR_ENABLE_FAST_EXPERIMENT = false;' in source
    assert 'Serial.write(byte)' not in source and 'renderQrText' not in source
    assert 'readiness::cameraDiagnostics && (!lastPublished' in source
    for function in ['streamHandler', 'qrFrameHandler']:
        assert re.search(r'esp_err_t '+function+r'\(httpd_req_t \*request\) \{\s*if \(!readiness::cameraDiagnostics\)', source)
    for item in baseline['library_files']:
        assert hashlib.sha256((ROOT / item['path']).read_bytes()).hexdigest() == item['sha256']
    new_config = configparser.ConfigParser()
    new_config.read(ROOT/'platformio.ini')
    current_environment = dict(new_config['env:esp32cam'])
    assert current_environment.pop('extra_scripts').split() == ['pre:scripts/private_wifi.py', 'pre:scripts/private_iot.py']
    assert baseline['default_environment'] == current_environment
    assert new_config['platformio']['default_envs'] == 'esp32cam'
    print('PASS: portable camera/pins/decoder/config baseline; 1000ms/no fast experiment; no payload logging; image guards', flush=True)
    run(['php', ROOT/'test/iot01-vectors.php', args.autoload, out])
    objects = []
    for file in (ROOT/'lib/quirc/lib').glob('*.c'):
        obj = out/(file.stem+'.o')
        run(['gcc', '-O2', '-I'+str(ROOT/'lib/quirc/lib'), '-c', file, '-o', obj])
        objects.append(obj)
    executable = out/'readiness.exe'
    includes=['-I'+str(ROOT/'include'), '-I'+str(ROOT/'lib/quirc/lib')]
    run(['g++', '-std=c++17', '-O2', '-Wall', '-Wextra', *includes, ROOT/'test/iot01-readiness.cpp', *objects, '-o', executable])
    run([executable])
    vectors = json.loads((ROOT/'test/iot01-vectors.json').read_text())
    for vector in vectors:
        run([executable, out/(vector['name']+'.pgm'), vector['payload'], '1' if vector['format'] else '0'])
        print('PASS: exact decoded bytes and format hint: '+vector['name'], flush=True)
    extracted = section(source, 'String quoteJson(', 'esp_err_t qrFrameHandler(')
    extracted += section(source, 'bool startServers()', 'void connectWifi()')
    (out/'http-functions.inc').write_text(extracted, encoding='utf-8')
    for diagnostics in [0,1]:
        exe=out/('http-'+str(diagnostics)+'.exe')
        run(['g++','-std=c++17','-O2','-Wall','-Wextra','-DSB_CAMERA_DIAGNOSTICS='+str(diagnostics),*includes,'-I'+str(out),ROOT/'test/iot01-http.cpp','-o',exe])
        run([exe])
    print('PASS: IoT-01 host readiness tests and '+str(len(vectors))+' synthetic quirc image decodes. NOT physical camera validation.', flush=True)


if __name__ == '__main__':
    main()
