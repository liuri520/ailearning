/**
 * router/index.js —— 前台路由（Hash 模式）
 *
 * 【为什么用 Hash 路由】SPEC D8 的取舍：
 * 线上没有 .htaccess，无法做 rewrite，任何 /post/xxx 形式的路径
 * Apache 都会去找一个不存在的目录。Hash（#/post/xxx）完全在浏览器内解析，
 * 不产生服务端请求，因此部署到任意子目录都能用，代价是 SEO（已由 share.php 补偿）。
 */
import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('../views/Home.vue'),
    meta: { title: '首页' },
  },
  {
    path: '/posts',
    name: 'posts',
    component: () => import('../views/PostList.vue'),
    meta: { title: '文章' },
  },
  {
    path: '/videos',
    name: 'videos',
    component: () => import('../views/VideoList.vue'),
    meta: { title: '视频' },
  },
  {
    path: '/gallery',
    name: 'gallery',
    component: () => import('../views/Gallery.vue'),
    meta: { title: '图集' },
  },
  {
    path: '/post/:slug',
    name: 'post',
    component: () => import('../views/PostDetail.vue'),
    props: true,
    meta: { title: '详情' },
  },
  {
    path: '/archive',
    name: 'archive',
    component: () => import('../views/Archive.vue'),
    meta: { title: '归档' },
  },
  {
    path: '/tags',
    name: 'tags',
    component: () => import('../views/TagList.vue'),
    meta: { title: '标签' },
  },
  {
    path: '/tag/:slug',
    name: 'tag',
    component: () => import('../views/TagDetail.vue'),
    props: true,
    meta: { title: '标签' },
  },
  {
    path: '/search',
    name: 'search',
    component: () => import('../views/Search.vue'),
    meta: { title: '搜索' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../views/NotFound.vue'),
    meta: { title: '页面不存在' },
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,

  /**
   * 回到顶部。
   * 【例外】浏览器前进/后退时应该恢复原来的滚动位置，
   * 强制归零会让用户回到列表时丢失浏览进度。
   */
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    return { top: 0 }
  },
})

export default router
