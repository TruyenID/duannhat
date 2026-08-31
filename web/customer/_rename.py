import os, shutil
base = '/Users/dxs-staff004/godx-tempo/customer-web/components'
pairs = [('Header.tsx', 'header.tsx'), ('Sidebar.tsx', 'sidebar.tsx')]
for old, new in pairs:
    src = os.path.join(base, old)
    tmp = os.path.join(base, '_tmp_rename')
    dst = os.path.join(base, new)
    if os.path.exists(src):
        shutil.copy2(src, tmp)
        os.remove(src)
        os.rename(tmp, dst)
        print(f'Renamed {old} -> {new}')
    else:
        print(f'SKIP: {old} not found')
print('Done')
