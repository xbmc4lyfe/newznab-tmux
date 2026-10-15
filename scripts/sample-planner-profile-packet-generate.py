from pathlib import Path
import difflib, hashlib, json, zipfile, subprocess
base=Path('/Users/allen/.codex/worktrees/sample-planner-cold-profile/newznab-tmux')
out=Path('/tmp/sample-planner-php-profile')
rel='app/Services/Releases/Verification/NativeSpotCheckPlanner.php'
s=(base/rel).read_text(); original=s

def region(text,start,end,stage,extra=''):
 a=text.index(start); b=text.index(end,a)
 body=text[a:b]
 indent=' '*(len(start)-len(start.lstrip(' ')))
 wrapped=f'{indent}$attribution{stage} = self::attributionBegin({stage}{extra});\n{indent}try {{\n'+''.join('    '+line if line.strip() else line for line in body.splitlines(True))+f'{indent}}} finally {{\n{indent}    self::attributionEnd($attribution{stage});\n{indent}}}\n'
 return text[:a]+wrapped+text[b:]

# Smallest regions first; original executable statements remain in order.
s=region(s,'        if ($maximumArticles < 1 || $maximumHeaderBytes < 1) {','        $library = $this->library();',2)
s=region(s,'        if ($library === null || ! array_is_list($manifest[\'files\'])) {','        try {\n            $input = json_encode',4)
s=region(s,'        try {\n            $input = json_encode','        $result = $this->call',5)
s=region(s,'            $library = FFI::cdef','            $knownInput =',11)
s=s.replace('$this->call($library, $knownInput, 1, 2, null)', '$this->call($library, $knownInput, 1, 2, null, 1)')
s=s.replace('?int $maximumArticleBytes): ?array\n    {','?int $maximumArticleBytes, int $attributionRole = 2): ?array\n    {')
s=region(s,'            // @phpstan-ignore method.notFound\n            $pointer =','            if ($pointer === null',7,', $attributionRole, $attributionInvocation')
s=region(s,'            try {\n                $encoded = FFI::string','            $decoded = json_decode',8,', $attributionRole, $attributionInvocation')
s=region(s,'            $decoded = json_decode','\n            return is_array($decoded)',9,', $attributionRole, $attributionInvocation')

# Wrap complete original method bodies, preserving all early returns and catches.
for method,stage in [('supportsInventory',1),('select',3),('library',6),('call',10),('validResult',12)]:
 start=s.index('    public ' if method in ['supportsInventory','select'] else '    private ',s.index('class NativeSpotCheckPlanner')) if False else s.index('function '+method+'(')
 a=s.index('    {\n',start)+len('    {\n')
 # next method doc starts after closing method; brace count isolates body.
 depth=1; i=a
 # PHP heredoc only C declarations, no braces. PHP strings in source don't contain unmatched braces.
 while depth:
  if s[i]=='{': depth+=1
  if s[i]=='}': depth-=1
  i+=1
 b=i-1
 body=s[a:b]; assert body.endswith('    ')
 body=body[:-4]
 prefix=''
 extra=''
 if method=='call':
  prefix='        $attributionInvocation = ++self::$attributionInvocation;\n        if ($attributionInvocation > 8) {\n            self::$attributionOverflow = true;\n        }\n'
  extra=', $attributionRole, $attributionInvocation'
 wrapped=prefix+f'        $attributionMethod{stage} = self::attributionBegin({stage}{extra});\n        try {{\n'+''.join('    '+line if line.strip() else line for line in body.splitlines(True))+f'        }} finally {{\n            self::attributionEnd($attributionMethod{stage});\n        }}\n    '
 s=s[:a]+wrapped+s[b:]
helper='''    // PRIVATE DIAGNOSTIC COPY ONLY. No native observer, reset, logging or I/O.
    private static int $attributionPhase = 0;
    private static int $attributionPublicOrdinal = 0;
    private static int $attributionInvocation = 0;
    private static bool $attributionOverflow = false;
    /** @var list<array{int,int,int,int,int,int,int,int}> */
    private static array $attributionTrace = [];

    public static function attributionPhase(int $phase, int $publicOrdinal): void
    {
        self::$attributionPhase = $phase;
        self::$attributionPublicOrdinal = $publicOrdinal;
    }

    /** @return array{capacity:int,invocation_capacity:int,overflow:bool,invocations:int,events:list<array{int,int,int,int,int,int,int,int}>} */
    public static function attributionRead(): array
    {
        return ['capacity' => 128, 'invocation_capacity' => 8, 'overflow' => self::$attributionOverflow,
            'invocations' => self::$attributionInvocation, 'events' => self::$attributionTrace];
    }

    private static function attributionBegin(int $stage, int $role = 0, int $invocation = 0): int
    {
        $slot = count(self::$attributionTrace);
        if ($slot >= 128) {
            self::$attributionOverflow = true;
            return -1;
        }
        self::$attributionTrace[$slot] = [self::$attributionPhase, self::$attributionPublicOrdinal,
            $invocation, $role, $stage, hrtime(true), 0, 0];
        return $slot;
    }

    private static function attributionEnd(int $slot): void
    {
        $ended = hrtime(true);
        if ($slot >= 0) {
            self::$attributionTrace[$slot][6] = $ended;
            self::$attributionTrace[$slot][7] = 1;
        }
    }

'''
s=s.replace('    private const int MINIMUM_ARTICLES',helper+'    private const int MINIMUM_ARTICLES',1)
s=s.replace('class NativeSpotCheckPlanner\n', 'class ProfiledNativeSpotCheckPlanner extends NativeSpotCheckPlanner\n',1)
(out/'NativeSpotCheckPlanner.control.php').write_text(original)
(out/'NativeSpotCheckPlanner.instrumented.php').write_text(s)
# Formatting uses only the existing locked tool; no build or functional execution.
subprocess.run(['php', str(base/'vendor/bin/pint'), str(out/'NativeSpotCheckPlanner.instrumented.php'), str(out/'driver.php')], cwd=base, check=True)
s=(out/'NativeSpotCheckPlanner.instrumented.php').read_text()
(out/'instrumentation.patch').write_text(''.join(difflib.unified_diff(original.splitlines(True),s.splitlines(True),fromfile='control/'+rel,tofile='instrumented/'+rel)))
zip_path=Path('/tmp/sample-plan-current-x86-raw.zip')
with zipfile.ZipFile(zip_path) as z:
 source=z.read('sample-planning/source/'+rel)
 assert source==original.encode(), 'Archived source does not match worktree'
 (out/'native.so.sha256').write_text(hashlib.sha256(z.read('sample-planning/native.so')).hexdigest()+'  sample-planning/native.so\n')
manifest={'status':'private_source_locked_pint_formatted_no_functional_or_timing_execution','source_commit':'0e62874c6a1090072de9fac50e50a6ec7f4c5bea','source_sha256':hashlib.sha256(original.encode()).hexdigest(),'archive_sha256':hashlib.sha256(zip_path.read_bytes()).hexdigest(),'files':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in out.iterdir() if p.is_file() and p.name != "manifest.json"}}
(out/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print(json.dumps(manifest,indent=2))
