<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { SwitchButton } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'
import { roleLabel, ROLE_TAG } from '@/utils/format'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const activeMenu = computed(() => {
  if (route.path.startsWith('/processes/create')) return '/processes/create'
  if (route.path.startsWith('/users')) return '/users'
  return '/processes'
})

async function onLogout(): Promise<void> {
  try {
    await ElMessageBox.confirm(t('app.logoutConfirm.message'), t('app.logoutConfirm.title'), {
      confirmButtonText: t('app.logout'),
      cancelButtonText: t('common.cancel'),
      type: 'warning',
    })
  } catch {
    return
  }
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <el-container class="layout">
    <el-header class="layout-header">
      <router-link to="/processes" class="brand">
        <el-icon :size="22"><Share /></el-icon>
        <span class="brand-title">{{ t('app.name') }}</span>
        <span class="brand-short">{{ t('app.short') }}</span>
      </router-link>
      <div class="user-box">
        <template v-if="auth.user">
          <el-icon><User /></el-icon>
          <span class="email">{{ auth.user.email }}</span>
          <el-tag :type="ROLE_TAG[auth.user.role]" size="small" effect="dark">
            {{ roleLabel(auth.user.role) }}
          </el-tag>
        </template>
        <LanguageSwitcher dark />
        <el-button text :icon="SwitchButton" @click="onLogout">{{ t('app.logout') }}</el-button>
      </div>
    </el-header>
    <el-container class="layout-body">
      <el-aside class="layout-aside" width="var(--wps-sidebar-w)">
        <el-menu :default-active="activeMenu" router class="side-menu">
          <el-menu-item index="/processes">
            <el-icon><List /></el-icon>
            <span>{{ t('nav.processes') }}</span>
          </el-menu-item>
          <el-menu-item v-if="auth.canEdit" index="/processes/create">
            <el-icon><CirclePlus /></el-icon>
            <span>{{ t('nav.processCreate') }}</span>
          </el-menu-item>
          <el-menu-item v-if="auth.isAdmin" index="/users">
            <el-icon><UserFilled /></el-icon>
            <span>{{ t('nav.users') }}</span>
          </el-menu-item>
        </el-menu>
      </el-aside>
      <el-main class="layout-main">
        <slot />
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.layout {
  height: 100%;
}
.layout-header {
  height: var(--wps-header-h);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #1f2d3d;
  color: #fff;
  padding: 0 20px;
  position: sticky;
  top: 0;
  z-index: 10;
}
.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #fff;
  font-weight: 600;
  font-size: 16px;
}
.brand:hover {
  text-decoration: none;
}
.brand-short {
  background: var(--el-color-primary);
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 12px;
}
.user-box {
  display: flex;
  align-items: center;
  gap: 8px;
}
.user-box .el-button {
  color: #fff;
}
.email {
  font-size: 13px;
}
.layout-body {
  height: calc(100% - var(--wps-header-h));
}
.layout-aside {
  background: #fff;
  border-right: 1px solid var(--el-border-color-light);
}
.side-menu {
  border-right: none;
}
.layout-main {
  padding: 20px 24px;
  overflow: auto;
}
</style>
